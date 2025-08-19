<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Url\Url;

class ValidDomainWithSchema implements ValidationRule
{
    protected array $failedDomains = [];
    
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return; // nullable is handled separately
        }
        
        $domains = str($value)->explode(',')->map(fn($d) => trim($d));
        
        foreach ($domains as $domain) {
            if (empty($domain)) {
                continue;
            }
            
            // Check if domain has schema
            if (!preg_match('/^https?:\/\//i', $domain)) {
                $this->failedDomains[] = $domain;
                continue;
            }
            
            // Validate URL structure using Spatie\Url
            try {
                $url = Url::fromString($domain);
                if (!$url->getHost()) {
                    $this->failedDomains[] = $domain;
                }
            } catch (\Exception $e) {
                $this->failedDomains[] = $domain;
            }
        }
        
        if (!empty($this->failedDomains)) {
            if (count($this->failedDomains) === 1) {
                $fail("Domain '{$this->failedDomains[0]}' must include http:// or https:// scheme.");
            } else {
                $failedList = implode(', ', array_map(fn($d) => "'$d'", $this->failedDomains));
                $fail("The following domains must include http:// or https:// schema: {$failedList}");
            }
        }
    }
}