<?php

namespace Ycs77\LaravelWizard;

use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;

class Wizard
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The wizard cache manager instance.
     *
     * @var \Ycs77\LaravelWizard\Contracts\CacheStore|\Ycs77\LaravelWizard\CacheManager
     */
    protected $cache;

    /**
     * The step repository instance.
     *
     * @var \Ycs77\LaravelWizard\StepRepository
     */
    protected $stepRepo;

    /**
     * The wizard options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * The wizard options extract key from config.
     *
     * @var array
     */
    protected $optionsKeys = [
        'cache',
        'driver',
        'connection',
        'table',
    ];

    /**
     * Create a new Wizard instance.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Load the wizard dependencies.
     *
     * @param  string  $name
     * @param  mixed  $steps
     * @param  array  $options
     * @return self
     */
    public function load($name, $steps, $options = [])
    {
        $this->setCache();
        $this->setStepRepo();

        $this->cache->setWizardName($name);
        $this->stepRepo->make($steps);

        $this->setOptions($options);
    }

    /**
     * Cache step data to store.
     *
     * @param  array  $data
     * @param  int|null  $nextStepIndex
     * @return void
     */
    public function cacheStepData(array $data, $nextStepIndex = null)
    {
        $this->cache->set($data, $nextStepIndex);
    }

    /**
     * Get the next step index.
     *
     * @return int|null
     */
    public function nextStepIndex()
    {
        $nextStepIndex = null;

        if ($nextStep = $this->stepRepo->next()) {
            $nextStepIndex = $nextStep->index();
        }

        return $nextStepIndex;
    }

    /**
     * Get the wizard cache instance.
     *
     * @return \Ycs77\LaravelWizard\Contracts\CacheStore|\Ycs77\LaravelWizard\CacheManager
     */
    public function cache()
    {
        return $this->cache;
    }

    /**
     * Set the wizard cache instance.
     *
     * @param  \Ycs77\LaravelWizard\Contracts\CacheStore|null  $cache
     * @return self
     */
    public function setCache($cache = null)
    {
        $this->cache = $cache ?? new CacheManager($this, $this->app);
        return $this;
    }

    /**
     * Get the step repository instance.
     *
     * @return \Ycs77\LaravelWizard\StepRepository
     */
    public function stepRepo()
    {
        return $this->stepRepo;
    }

    /**
     * Set the step repository instance.
     *
     * @param  \Ycs77\LaravelWizard\StepRepository|null  $stepRepo
     * @return self
     */
    public function setStepRepo($stepRepo = null)
    {
        $this->stepRepo = $stepRepo ?? new StepRepository($this);
        return $this;
    }

    /**
     * Get the wizard options.
     *
     * @return array
     */
    public function options()
    {
        return $this->options;
    }

    /**
     * Get the wizard option.
     *
     * @param  string  $key
     * @return mixed
     */
    public function option(string $key)
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Get the wizard options.
     *
     * @param  array  $options
     * @return self
     */
    public function setOptions(array $options = [])
    {
        $config = Arr::only(
            $this->app['config']['wizard'],
            $this->optionsKeys
        );

        $this->options = array_merge($config, $options);

        return $this;
    }

    /**
     * Get the application instance.
     *
     * @return  \Illuminate\Foundation\Application
     */
    public function getApp()
    {
        return $this->app;
    }

    /**
     * Handle dynamic method calls into the wizard.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->stepRepo->$method(...$parameters);
    }
}
