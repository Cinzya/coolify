<form wire:submit.prevent='submit' class="flex flex-col w-full gap-2">
    <div class="pb-2">Note: If a service has a defined port, do not delete it. <br>If you want to use your custom
        domain, you can add it with a port.</div>
    <x-forms.input placeholder="https://app.coolify.io" label="Domains" id="application.fqdn"
        helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "
        :class="$this->hasInvalidDomains() ? 'border-red-500 dark:border-red-500 focus:border-red-500 dark:focus:border-red-500 ring-red-500 dark:ring-red-500' : ''" ></x-forms.input>
    @if ($this->hasInvalidDomains())
        <label class="label">
            <span class="text-red-500 label-text-alt">Every domain must include the http:// or https:// scheme.</span>
        </label>
    @endif
    <x-forms.button type="submit">Save</x-forms.button>
</form>
