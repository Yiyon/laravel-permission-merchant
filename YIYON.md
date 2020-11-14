```php
安装易用多商户版本
composer require yiyon/laravel-permission-merchant
增加 Provider
config/app.php
'providers' => [
    // ...
    Yiyon\Permission\PermissionServiceProvider::class,
];

php artisan vendor:publish --provider="Yiyon\Permission\PermissionServiceProvider" --tag="migrations"
php artisan migrate
php artisan vendor:publish --provider="Yiyon\Permission\PermissionServiceProvider" --tag="config"
```
