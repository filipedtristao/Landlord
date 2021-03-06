<?php

namespace HipsterJazzbo\Landlord;

use HipsterJazzbo\Landlord\Exceptions\TenantColumnUnknownException;
use HipsterJazzbo\Landlord\Exceptions\TenantNullIdException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use function app;
use function collect;

class TenantManager
{
    use Macroable;

    /**
     * @var bool
     */
    protected $enabled = true;

    /**
     * @var Collection
     */
    protected $tenants;

    /**
     * @var Collection
     */
    protected $deferredModels;
    protected $scopeName = 'tenant';

    protected $request;
    
    /**
     * Landlord constructor.
     */
    public function __construct()
    {
        $this->tenants = collect();
        $this->deferredModels = collect();
        $this->request = app()->make(Request::class);
    }

    /**
     * Enable scoping by tenantColumns.
     *
     * @return void
     */
    public function enable()
    {
        $this->enabled = true;
    }

    /**
     * Disable scoping by tenantColumns.
     *
     * @return void
     */
    public function disable()
    {
        $this->enabled = false;
    }

    /**
     * Add a tenant to scope by.
     *
     * @param string|Model $tenant
     * @param mixed|null   $id
     *
     * @throws TenantNullIdException
     */
    public function addTenant($tenant, $id = null)
    {
        if (func_num_args() == 1 && $tenant instanceof Model) {
            $id = $tenant->getKey();
        }

        if (is_null($id)) {
            throw new TenantNullIdException('$id must not be null');
        }

        $this->tenants->put($this->getTenantKey($tenant), $id);
    }

    /**
     * Remove a tenant so that queries are no longer scoped by it.
     *
     * @param string|Model $tenant
     */
    public function removeTenant($tenant)
    {
        $this->tenants->pull($this->getTenantKey($tenant));
    }

    /**
     * Whether a tenant is currently being scoped.
     *
     * @param string|Model $tenant
     *
     * @return bool
     */
    public function hasTenant($tenant)
    {
        return $this->tenants->has($this->getTenantKey($tenant));
    }

    /**
     * @return Collection
     */
    public function getTenants()
    {
        return $this->tenants;
    }

    /**
     * @param $tenant
     *
     * @throws TenantColumnUnknownException
     *
     * @return mixed
     */
    public function getTenantId($tenant)
    {
        if (!$this->hasTenant($tenant)) {
            throw new TenantColumnUnknownException(
                '$tenant must be a string key or an instance of \Illuminate\Database\Eloquent\Model'
            );
        }

        return $this->tenants->get($this->getTenantKey($tenant));
    }

    /**
     * Applies applicable tenant scopes to a model.
     *
     * @param Model|BelongsToTenants $model
     */
    public function applyTenantScopes(Model $model)
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->tenants->isEmpty()) {
            // No tenants yet, defer scoping to a later stage
            $this->deferredModels->push($model);

            return;
        }

        $landlord = $this;

        $model->addGlobalScope($this->scopeName, function (Builder $builder) use ($model, $landlord) {
            $builder->where(function ($builder) use ($model, $landlord) {
                $landlord->applySharingScopeQuery($builder, $model);

                $landlord->modelTenants($model)->each(function ($id, $tenant) use ($builder, $model, $landlord) {
                    $landlord->applyTenantScopeQuery($builder, $model, $tenant, $id);
                });
            });
        });
    }

    /**
     * Applies applicable tenant scopes to deferred model booted before tenants setup.
     */
    public function applyTenantScopesToDeferredModels()
    {
        $this->deferredModels->each(function ($model) {
            $landlord = $this;
            $model->addGlobalScope($this->scopeName, function (Builder $builder) use ($model, $landlord) {
                $builder->where(function ($builder) use ($model, $landlord) {
                    $landlord->modelTenants($model)->each(function ($id, $tenant) use ($builder, $model, $landlord) {
                        if (!isset($model->{$tenant})) {
                            $model->setAttribute($tenant, $id);
                        }
                        $landlord->applyTenantScopeQuery($builder, $model, $tenant, $id);
                    });
                });
            });
        });

        $this->deferredModels = collect();
    }

    private function applyTenantScopeQuery($builder, $model, $tenant, $id)
    {
        if ($this->getTenants()->first() && $this->getTenants()->first() != $id) {
            $id = $this->getTenants()->first();
        }

        $builder->orWhere($model->getQualifiedTenant($tenant), '=', $id);

        if ($model->hasDefaultRecords) {
            $builder->orWhereNull($model->getQualifiedTenant($tenant));
        }
    }

    private function applySharingScopeQuery($builder, $model)
    {
        if ($model->hasCompanySharing) {
            $tenant_id = $this->getTenants()->first();
            $builder->orWhereHas('sharedWithCompaniesAndEmails', function ($builder) use ($tenant_id) {
                $builder->where(function ($builder) use ($tenant_id) {
                    $builder->where('referenced_company_id', $tenant_id);

                    if (($token = request()->get('_share_token'))){
                        $builder->orWhere('token', $token);
                    }
                });
                
                if ($this->request->get('tenancy_share_approval_status')) {
                    if (is_array($this->request->get('tenancy_share_approval_status'))) {
                        $builder->whereIn('approval_status', $this->request->get('tenancy_share_approval_status'));
                    } else {
                        $builder->where('approval_status', $this->request->get('tenancy_share_approval_status'));
                    }
                } else {
                    $builder->where('approval_status', '<>', 3);
                }
                
                return $builder;
            });
        }
    }

    /**
     * Add tenant columns as needed to a new model instance before it is created.
     *
     * @param Model $model
     */
    public function newModel(Model $model)
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->tenants->isEmpty()) {
            // No tenants yet, defer scoping to a later stage
            $this->deferredModels->push($model);

            return;
        }

        $this->modelTenants($model)->each(function ($tenantId, $tenantColumn) use ($model) {
            if (!isset($model->{$tenantColumn})) {
                $model->setAttribute($tenantColumn, $tenantId);
            }
        });
    }

    /**
     * Get a new Eloquent Builder instance without any of the tenant scopes applied.
     *
     * @param Model $model
     *
     * @return Builder
     */
    public function newQueryWithoutTenants(Model $model)
    {
        return $model->newQuery()->withoutGlobalScopes([$this->scopeName]);
    }

    /**
     * Get the key for a tenant, either from a Model instance or a string.
     *
     * @param string|Model $tenant
     *
     * @throws TenantColumnUnknownException
     *
     * @return string
     */
    protected function getTenantKey($tenant)
    {
        if ($tenant instanceof Model) {
            $tenant = $tenant->getForeignKey();
        }

        if (!is_string($tenant)) {
            throw new TenantColumnUnknownException(
                '$tenant must be a string key or an instance of \Illuminate\Database\Eloquent\Model'
            );
        }

        return $tenant;
    }

    /**
     * Get the tenantColumns that are actually applicable to the given
     * model, in case they've been manually specified.
     *
     * @param Model|BelongsToTenants $model
     *
     * @return Collection
     */
    protected function modelTenants(Model $model)
    {
        return $this->tenants->only($model->getTenantColumns());
    }
}
