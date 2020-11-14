<?php

namespace Yiyon\Permission\Models;

use Yiyon\Permission\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Yiyon\Permission\Traits\HasPermissions;
use Yiyon\Permission\Exceptions\RoleDoesNotExist;
use Yiyon\Permission\Exceptions\GuardDoesNotMatch;
use Yiyon\Permission\Exceptions\RoleAlreadyExists;
use Yiyon\Permission\Contracts\Role as RoleContract;
use Yiyon\Permission\Traits\RefreshesPermissionCache;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model implements RoleContract
{
    use HasPermissions;
    use RefreshesPermissionCache;

    protected $guarded = ['id'];

    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? config('auth.defaults.guard');

        parent::__construct($attributes);

        $this->setTable(config('permission.table_names.roles'));
    }

    /**
     * 查询的时候，默认增加商户编号
     */
    protected static function boot()
    {
        parent::boot();
        //默认加上商户编号
        $merchantscope = config('permission.merchant.merchantscope');
        static::addGlobalScope($merchantscope,
            function (Builder $builder) {
                $guard       = config('permission.merchant.guard');
                $merchant_id = config('permission.merchant.merchant_id');
                $builder->where(config('permission.table_names.roles') . '.' . $merchant_id,
                                '=',
                                auth($guard)->user()->merchant_id);
            });
    }

    public static function create(array $attributes = [])
    {
        $attributes['guard_name'] = $attributes['guard_name'] ?? Guard::getDefaultName(static::class);
        //创建的时候，默认增加商户编号
        $guard                    = config('permission.merchant.guard');
        $merchant_id              = config('permission.merchant.merchant_id');
        $attributes[$merchant_id] = auth($guard)->user()->merchant_id;
        if (static::where('name', $attributes['name'])
                  ->where('guard_name', $attributes['guard_name'])
                  ->first())
        {
            throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        if (isNotLumen() && app()::VERSION < '5.4')
        {
            return parent::create($attributes);
        }

        return static::query()
                     ->create($attributes);
    }

    /**
     * A role may be given various permissions.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(config('permission.models.permission'),
                                    config('permission.table_names.role_has_permissions'),
                                    'role_id',
                                    'permission_id');
    }

    /**
     * A role belongs to some users of the model associated with its guard.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(getModelForGuard($this->attributes['guard_name']),
                                    'model',
                                    config('permission.table_names.model_has_roles'),
                                    'role_id',
                                    config('permission.column_names.model_morph_key'));
    }

    /**
     * Find a role by its name and guard name.
     *
     * @param string      $name
     * @param string|null $guardName
     *
     * @return \Yiyon\Permission\Contracts\Role|\Yiyon\Permission\Models\Role
     *
     * @throws \Yiyon\Permission\Exceptions\RoleDoesNotExist
     */
    public static function findByName(string $name, $guardName = null): RoleContract
    {
        $guardName = $guardName ?? Guard::getDefaultName(static::class);

        $role = static::where('name', $name)
                      ->where('guard_name', $guardName)
                      ->first();

        if (!$role)
        {
            throw RoleDoesNotExist::named($name);
        }

        return $role;
    }

    public static function findById(int $id, $guardName = null): RoleContract
    {
        $guardName = $guardName ?? Guard::getDefaultName(static::class);

        $role = static::where('id', $id)
                      ->where('guard_name', $guardName)
                      ->first();

        if (!$role)
        {
            throw RoleDoesNotExist::withId($id);
        }

        return $role;
    }

    /**
     * Find or create role by its name (and optionally guardName).
     *
     * @param string      $name
     * @param string|null $guardName
     *
     * @return \Yiyon\Permission\Contracts\Role
     */
    public static function findOrCreate(string $name, $guardName = null): RoleContract
    {
        $guardName = $guardName ?? Guard::getDefaultName(static::class);

        $role = static::where('name', $name)
                      ->where('guard_name', $guardName)
                      ->first();

        if (!$role)
        {
            return static::query()
                         ->create(['name' => $name, 'guard_name' => $guardName]);
        }

        return $role;
    }

    /**
     * Determine if the user may perform the given permission.
     *
     * @param string|Permission $permission
     *
     * @return bool
     *
     * @throws \Yiyon\Permission\Exceptions\GuardDoesNotMatch
     */
    public function hasPermissionTo($permission): bool
    {
        $permissionClass = $this->getPermissionClass();

        if (is_string($permission))
        {
            $permission = $permissionClass->findByName($permission, $this->getDefaultGuardName());
        }

        if (is_int($permission))
        {
            $permission = $permissionClass->findById($permission, $this->getDefaultGuardName());
        }

        if (!$this->getGuardNames()
                  ->contains($permission->guard_name))
        {
            throw GuardDoesNotMatch::create($permission->guard_name, $this->getGuardNames());
        }

        return $this->permissions->contains('id', $permission->id);
    }
}
