<?php

namespace Ycs77\LaravelWizard\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Ycs77\LaravelWizard\Exceptions\StepNotFoundException;
use Ycs77\LaravelWizard\Step;
use Ycs77\LaravelWizard\Wizard;

class WizardController extends Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * The wizard instance.
     *
     * @var \Ycs77\LaravelWizard\Wizard
     */
    protected $wizard;

    /**
     * The wizard name.
     *
     * @var string
     */
    protected $wizardName = '';

    /**
     * The wizard title.
     *
     * @var string
     */
    protected $wizardTitle = '';

    /**
     * The wizard options.
     *
     * Available options reference from Ycs77\LaravelWizard\Wizard::$optionsKeys.
     *
     * @var array
     */
    protected $wizardOptions = [];

    /**
     * The wizard steps instance.
     *
     * @var array
     */
    protected $steps = [];

    /**
     * The wizard done show texts.
     *
     * @var string
     */
    protected $doneText;

    /**
     * Create new wizard controller.
     *
     * @param  \Ycs77\LaravelWizard\Wizard  $wizard
     * @return void
     */
    public function __construct(Wizard $wizard)
    {
        $this->wizard = $wizard;
        $this->wizard->load($this->wizardName, $this->steps, $this->wizardOptions);
    }

    /**
     * Show the wizard form.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $step
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function create(Request $request, $step = null)
    {
        $lastProcessedIndex = $this->getLastProcessedStepIndex($request);

        // If step is null, redirect to last processed index.
        if (is_null($step)) {
            return $this->redirectToLastProcessedStep(
                $request,
                $lastProcessedIndex
            );
        }

        $step = $this->getWizardStep($request, $step);

        // Check this step is not last processed step.
        if ($step->index() !== $lastProcessedIndex) {
            // Redirect to last processed step.
            return $this->redirectToLastProcessedStep(
                $request,
                $lastProcessedIndex
            );
        }

        $wizard = $this->wizard();
        $wizardTitle = $this->wizardTitle;
        $stepRepo = $this->wizard()->stepRepo();
        $formAction = $this->getActionMethod('create');
        $postAction = $this->getActionMethod('store');

        return view('wizard::base', compact('wizard', 'wizardTitle', 'stepRepo', 'step', 'formAction', 'postAction'));
    }

    /**
     * Store wizard form data.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $step
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, string $step)
    {
        $step = $this->getWizardStep($request, $step);

        $this->validate($request, $step->rules($request));

        if ($this->wizard()->option('cache')) {
            $step->cacheProgress($request);
        } else {
            $step->saveData($request, $step->getRequestData($request), $step->model());
        }

        // If trigger from 'back',
        // Set this step index and redirect to prev step.
        if ($request->query('_trigger') === 'back') {
            $prevStep = $this->wizard()->stepRepo()->prev();
            return $this->setThisStepAndRedirectTo($request, $prevStep);
        }

        if (!$this->getNextStepSlug()) {
            // Wizard done...
            if ($this->wizard()->option('cache')) {
                $data = $this->save($request);
            }

            return $this->doneRedirectTo($data ?? null);
        }

        return $this->redirectTo();
    }

    /**
     * Show the done page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function done(Request $request)
    {
        $wizardData = $request->session()->get('wizard_data');
        $wizardData = json_decode(base64_decode($wizardData), true);
        $stepRepo = $this->wizard()->stepRepo();
        $doneText = $this->doneText;

        return view('wizard::done', compact('wizardData', 'stepRepo', 'doneText'));
    }

    /**
     * Set this step and redirect to this step.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Ycs77\LaravelWizard\Step  $step
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function setThisStepAndRedirectTo(Request $request, Step $step)
    {
        if ($this->wizard()->option('cache')) {
            $this->wizard()->cacheStepData(
                $this->wizard()->cache()->get(),
                $step->index()
            );
        }

        return redirect()->route(
            $request->route()->getName(),
            [$step->slug()]
        );
    }

    /**
     * Redirect to last processed step.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $lastProcessedIndex
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectToLastProcessedStep(Request $request, int $lastProcessedIndex)
    {
        $lastProcessedStep = $this->wizard()->stepRepo()->get($lastProcessedIndex);

        return redirect()->route(
            $request->route()->getName(),
            [$lastProcessedStep->slug()]
        );
    }

    /**
     * Step redirect response.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function redirectTo()
    {
        return redirect($this->getActionUrl('create', [$this->getNextStepSlug()]));
    }

    /**
     * Done redirect response.
     *
     * @param  array|null  $withData
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function doneRedirectTo($withData = null)
    {
        $withData = base64_encode(json_encode($withData ?? []));
        session()->put('wizard_data', $withData);
        return redirect($this->getActionUrl('done'));
    }

    /**
     * Get action class method name.
     *
     * @param  string  $method
     * @return string
     */
    protected function getActionMethod(string $method)
    {
        $className = static::class;
        $stepNamespace = config('wizard.namespace.controllers');
        $rootNamespace = trim(str_replace('/', '\\', $stepNamespace), '\\');

        if (Str::startsWith($className, $rootNamespace)) {
            $className = trim(str_replace($rootNamespace, '', $className), '\\');
        } else {
            $className = '\\' . trim($className, '\\');
        }

        return "$className@$method";
    }

    /**
     * Get action URL.
     *
     * @param  string  $method
     * @return string
     */
    protected function getActionUrl(string $method, $parameters = [])
    {
        return action($this->getActionMethod($method), $parameters);
    }

    /**
     * Get the last processed step index.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int
     */
    public function getLastProcessedStepIndex(Request $request)
    {
        if ($this->wizard()->option('cache')) {
            return $this->wizard()->cache()->getLastProcessedIndex() ?? 0;
        }

        return 0;
    }

    /**
     * Get wizard step.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null $slug
     * @return \Ycs77\LaravelWizard\Step
     *
     * @throws \Ycs77\LaravelWizard\Exceptions\StepNotFoundException
     */
    protected function getWizardStep(Request $request, $slug)
    {
        try {
            if (isset($slug)) {
                $step = $this->wizard()->stepRepo()->find($slug);
            } else {
                $lastProcessedStepIndex = $this->getLastProcessedStepIndex($request);
                $step = $this->wizard()->stepRepo()->get($lastProcessedStepIndex);
            }

            if (is_null($step)) {
                throw new StepNotFoundException();
            }

            $this->wizard()->stepRepo()->setCurrentIndex($step->index());

            $step->setModel($request);
        } catch (StepNotFoundException $e) {
            abort(404);
        }

        return $step;
    }

    /**
     * Get the next step slug.
     *
     * @return string|null
     */
    public function getNextStepSlug()
    {
        return $this->wizard()->stepRepo()->nextSlug();
    }

    /**
     * Save wizard data.
     *
     * Notice: If
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function save(Request $request)
    {
        /** @var \Ycs77\LaravelWizard\Step $step */
        foreach ($this->wizard()->stepRepo()->all() as $step) {
            $step->setModel($request);
            $step->saveData($request, $step->data(), $step->model());
        }

        $data = $this->wizard()->cache()->get();
        $this->wizard()->cache()->clear();
        return $data;
    }

    /**
     * Get the wizard instance.
     *
     * @return \Ycs77\LaravelWizard\Wizard
     */
    protected function wizard()
    {
        return $this->wizard;
    }
}
