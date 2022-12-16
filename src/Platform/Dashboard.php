<?php

declare(strict_types=1);

namespace Orchid\Platform;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use Orchid\Screen\Actions\Menu;
use Orchid\Screen\Screen;
use RuntimeException;

class Dashboard
{
    use Macroable;

    /**
     * ORCHID Version.
     */
    public const VERSION = '13.8.0';

    /**
     * Slug for main menu.
     */
    public const MENU_MAIN = 'Main';

    /**
     * Slug for dropdown profile.
     */
    public const MENU_PROFILE = 'Profile';

    /**
     * The Dashboard configuration options.
     *
     * @var array
     */
    protected static $options = [];

    /**
     * @var Collection
     */
    public $menu;

    /**
     * JS and CSS resources for implementation in the panel.
     *
     * @var Collection
     */
    public $resources;

    /**
     * Permission for applications.
     *
     * @var Collection
     */
    private $permission;

    /**
     * @var Collection
     */
    private $search;

    /**
     * @var Screen|null
     */
    private $currentScreen;

    /**
     * Dashboard constructor.
     */
    public function __construct()
    {
        $this->resources = collect();

        $this->permission = collect([
            'all'     => collect(),
            'removed' => collect(),
        ]);

        $this->search = collect();

        $this->flushState();
    }

    /**
     * Get the version number of the application.
     *
     * @return string
     */
    public static function version(): string
    {
        return static::VERSION;
    }

    /**
     * Determine published assets are up-to-date.
     *
     * @throws \RuntimeException
     *
     * @return bool
     */
    public static function assetsAreCurrent()
    {
        $publishedPath = public_path('vendor/orchid/mix-manifest.json');

        throw_unless(File::exists($publishedPath), new RuntimeException('Orchid assets are not published. Please run: `php artisan orchid:publish`'));

        return File::get($publishedPath) === File::get(__DIR__.'/../../public/mix-manifest.json');
    }

    /**
     * Get the route with the dashboard prefix.
     *
     * @param string $path
     *
     * @return string
     */
    public static function prefix(string $path = ''): string
    {
        $prefix = config('platform.prefix');

        return Str::start($prefix.$path, '/');
    }

    /**
     * Configure the Dashboard application.
     *
     * @param array $options
     *
     * @return void
     */
    public static function configure(array $options): void
    {
        static::$options = $options;
    }

    /**
     * Get a Dashboard configuration option.
     *
     * @param string     $key
     * @param mixed|null $default
     *
     * @return mixed
     */
    public static function option(string $key, $default = null)
    {
        return Arr::get(static::$options, $key, $default);
    }

    /**
     * @param string      $key
     * @param string|null $default
     *
     * @return mixed
     */
    public static function modelClass(string $key, string $default = null)
    {
        $model = static::model($key, $default);

        return class_exists($model) ? new $model() : $model;
    }

    /**
     * Get the class name for a given Dashboard model.
     *
     * @param string      $key
     * @param string|null $default
     *
     * @return string
     */
    public static function model(string $key, string $default = null): string
    {
        return Arr::get(static::$options, 'models.'.$key, $default ?? $key);
    }

    /**
     * Get the user model class name.
     *
     * @param string $key
     * @param string $custom
     */
    public static function useModel(string $key, string $custom): void
    {
        static::$options['models'][$key] = $custom;
    }

    /**
     * The real path to the package files.
     *
     * @param string $path
     *
     * @return string
     */
    public static function path(string $path = ''): string
    {
        $current = dirname(__DIR__, 2);

        return realpath($current.($path ? DIRECTORY_SEPARATOR.$path : $path));
    }

    /**
     * Registers a ItemPermission that defines authentication permissions.
     *
     * @param ItemPermission $permission
     *
     * @return $this
     */
    public function registerPermissions(ItemPermission $permission): self
    {
        if (empty($permission->group)) {
            return $this;
        }

        $old = $this->permission->get('all')
            ->get($permission->group, []);

        $this->permission->get('all')
            ->put($permission->group, array_merge_recursive($old, $permission->items));

        return $this;
    }

    /**
     * Registers a set of models for which full-text search is required.
     *
     * @param array $model
     *
     * @return $this
     */
    public function registerSearch(array $model): self
    {
        $this->search = $this->search->merge($model);

        return $this;
    }

    /**
     * @param string       $key
     * @param string|array $value
     *
     * @return Dashboard
     */
    public function registerResource(string $key, $value): self
    {
        $item = $this->resources->get($key, []);

        $this->resources[$key] = array_merge($item, Arr::wrap($value));

        return $this;
    }

    /**
     * Return CSS\JS.
     *
     * @param null $key
     *
     * @return array|Collection|mixed
     */
    public function getResource($key = null)
    {
        if ($key === null) {
            return $this->resources;
        }

        return $this->resources->get($key);
    }

    /**
     * @return Collection
     */
    public function getSearch(): Collection
    {
        return $this->search->transform(static fn ($model) => is_object($model) ? $model : resolve($model));
    }

    /**
     * @param array|string $groups
     *
     * @return Collection
     */
    public function getPermission($groups = []): Collection
    {
        $all = $this->permission->get('all')
            ->when(! empty($groups), fn (Collection $collection) => $collection->only($groups));

        $removed = $this->permission->get('removed');

        if (! $removed->count()) {
            return $all;
        }

        return $all->map(static function ($group) use ($removed) {
            foreach ($group[key($group)] as $key => $item) {
                if ($removed->contains($item)) {
                    unset($group[key($group)]);
                }
            }

            return $group;
        });
    }

    /**
     * Get all registered permissions with the enabled state.
     *
     * @param array|string $groups
     *
     * @return Collection
     */
    public function getAllowAllPermission($groups = []): Collection
    {
        return $this->getPermission($groups)
            ->collapse()
            ->reduce(static fn (Collection $permissions, array $item) => $permissions->put($item['slug'], true), collect());
    }

    /**
     * @param string $key
     *
     * @return $this
     */
    public function removePermission(string $key): self
    {
        $this->permission->get('removed')->push($key);

        return $this;
    }

    /**
     * @param Screen $screen
     *
     * @return $this
     */
    public function setCurrentScreen(Screen $screen): self
    {
        $this->currentScreen = $screen;

        return $this;
    }

    /**
     * @return Screen|null
     */
    public function getCurrentScreen(): ?Screen
    {
        return $this->currentScreen;
    }

    /**
     * Adding a new element to the menu.
     *
     * @param string                      $location
     * @param \Orchid\Screen\Actions\Menu $menu
     *
     * @return $this
     */
    public function registerMenuElement(string $location, Menu $menu): Dashboard
    {
        if ($menu->get('sort', 0) === 0) {
            $menu->sort($this->menu->get($location)->count() + 1);
        }

        $this->menu->get($location)->add($menu);

        return $this;
    }

    /**
     * Generate on the menu display.
     *
     * @param string $location
     *
     * @throws \Throwable
     *
     * @return string
     */
    public function renderMenu(string $location): string
    {
        return $this->menu->get($location)
            ->sort(fn (Menu $current, Menu $next) => $current->get('sort', 0) <=> $next->get('sort', 0))
            ->map(fn (Menu $menu) => (string) $menu->render())
            ->implode('');
    }

    /**
     * @param string $location
     *
     * @return bool
     */
    public function isEmptyMenu(string $location): bool
    {
        return $this->menu->get($location)->isEmpty();
    }

    /**
     * @param string $location
     * @param string $slug
     * @param Menu[] $list
     *
     * @return Dashboard
     */
    public function addMenuSubElements(string $location, string $slug, array $list): Dashboard
    {
        $menu = $this->menu->get($location)
            ->map(fn (Menu $menu) => $menu->get('slug') === $slug
                ? $menu->list($list)
                : $menu);

        $this->menu->put($location, $menu);

        return $this;
    }

    /**
     * Flush the persistent Orchid state.
     *
     * @return void
     */
    public function flushState(): void
    {
        $this->menu = collect([
            self::MENU_MAIN    => collect(),
            self::MENU_PROFILE => collect(),
        ]);

        $this->currentScreen = null;
    }
}
