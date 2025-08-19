<?php

namespace App\Livewire\Project\Service;

use App\Models\ServiceApplication;
use App\Rules\ValidDomainWithSchema;
use Livewire\Component;
use Spatie\Url\Url;

class EditDomain extends Component
{
    public $applicationId;

    public ServiceApplication $application;

    protected function rules()
    {
        return [
            'application.fqdn' => ['nullable', new ValidDomainWithSchema()],
            'application.required_fqdn' => 'required|boolean',
        ];
    }


    public function mount()
    {
        $this->application = ServiceApplication::find($this->applicationId);
        
    }

    public function updatedApplicationFqdn($value)
    {
        try {
            $this->validateOnly('application.fqdn');
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Extract validation error messages and display as toaster
            $errors = $e->validator->errors()->get('application.fqdn');
            foreach ($errors as $error) {
                $this->dispatch('error', $error);
            }
            // Re-throw to preserve normal validation behavior
            throw $e;
        }
    }

    public function hasInvalidDomains()
    {
        if (empty($this->application->fqdn)) {
            return false;
        }

        $domains = str($this->application->fqdn)->explode(',');
        foreach ($domains as $domain) {
            $trimmedDomain = trim($domain);
            if (!empty($trimmedDomain) && !preg_match('/^https?:\/\//i', $trimmedDomain)) {
                return true;
            }
        }
        return false;
    }

    public function submit()
    {
        try {
            $this->application->fqdn = str($this->application->fqdn)->replaceEnd(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->replaceStart(',', '')->trim();
            $this->application->fqdn = str($this->application->fqdn)->trim()->explode(',')->map(function ($domain) {
                Url::fromString($domain, ['http', 'https']);

                return str($domain)->trim()->lower();
            });
            $this->application->fqdn = $this->application->fqdn->unique()->implode(',');
            $warning = sslipDomainWarning($this->application->fqdn);
            if ($warning) {
                $this->dispatch('warning', __('warning.sslipdomain'));
            }
            check_domain_usage(resource: $this->application);
            $this->application->save();
            updateCompose($this->application);
            if (str($this->application->fqdn)->contains(',')) {
                $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
            }
            $this->application->service->parse();
            $this->dispatch('refresh');
            $this->dispatch('configurationChanged');
        } catch (\Throwable $e) {
            $originalFqdn = $this->application->getOriginal('fqdn');
            if ($originalFqdn !== $this->application->fqdn) {
                $this->application->fqdn = $originalFqdn;
            }

            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.edit-domain');
    }
}
