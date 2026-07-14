<?php

declare(strict_types=1);

/**
 * FOSSBilling registrar adapter for namingo/registrars.
 *
 *
 * SPDX-License-Identifier: MIT
 */

$autoload = __DIR__ . '/../../../namingo/vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}

use Namingo\Registrars\Registrar as NamingoRegistrars;
use Namingo\Registrars\Adapter\NameCom;
use Namingo\Registrars\Adapter\OpenSRS;
use Namingo\Registrars\Contact as NamingoContact;
use Namingo\Registrars\UpdateDetails as NamingoUpdateDetails;

class Registrar_Adapter_Connect extends Registrar_AdapterAbstract
{
    private const REGISTRAR_OPENSRS = 'opensrs';
    private const REGISTRAR_NAMECOM = 'namecom';

    private const ENVIRONMENT_TEST = 'test';
    private const ENVIRONMENT_PRODUCTION = 'production';

    public array $config = [
        'registrar' => self::REGISTRAR_OPENSRS,
        'environment' => self::ENVIRONMENT_TEST,
        'username' => '',
        'api_key' => '',
        'password' => '',
    ];

    private ?NamingoRegistrars $registrarClient = null;

    public function __construct($options)
    {
        if (!class_exists(NamingoRegistrars::class)) {
            throw new Registrar_Exception(
                'The Namingo Registrars Composer package is not installed. Run "composer require namingo/registrars" from the FOSSBilling installation directory.',
            );
        }

        $options = is_array($options) ? $options : [];

        $this->config['registrar'] = strtolower(trim((string) ($options['registrar'] ?? self::REGISTRAR_OPENSRS)));
        $this->config['environment'] = strtolower(trim((string) ($options['environment'] ?? self::ENVIRONMENT_TEST)));
        $this->config['username'] = trim((string) ($options['username'] ?? ''));
        $this->config['api_key'] = trim((string) ($options['api_key'] ?? ''));
        $this->config['password'] = (string) ($options['password'] ?? '');

        if (!in_array($this->config['registrar'], [self::REGISTRAR_OPENSRS, self::REGISTRAR_NAMECOM], true)) {
            throw new Registrar_Exception('Connect has an invalid registrar selection.');
        }

        if (!in_array($this->config['environment'], [self::ENVIRONMENT_TEST, self::ENVIRONMENT_PRODUCTION], true)) {
            throw new Registrar_Exception('Connect has an invalid environment selection.');
        }

        $this->requireSetting('username', 'Username');
        $this->requireSetting('api_key', 'API key / API token');

        if ($this->config['registrar'] === self::REGISTRAR_OPENSRS) {
            $this->requireSetting('password', 'Password');
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Connect domains through the Namingo Registrars library. Supports OpenSRS and Name.com, with separate test and production endpoints.',
            'form' => [
                'username' => ['text', [
                    'label' => 'Username',
                    'description' => 'The username used for registrar authentication, such as a reseller or API username.',
                ]],
                'api_key' => ['password', [
                    'label' => 'API Key / API Token',
                    'description' => 'The API key or token used for registrar authentication.',
                ]],
                'password' => ['password', [
                    'label' => 'Password / Secret Key',
                    'description' => 'The password or secret key, if required by the registrar.',
                    'required' => false,
                ]],
                'registrar' => ['select', [
                    'label' => 'Registrar',
                    'description' => 'Choose the registrar adapter used for this connection.',
                    'multiOptions' => [
                        self::REGISTRAR_OPENSRS => 'OpenSRS',
                        self::REGISTRAR_NAMECOM => 'Name.com',
                    ],
                ]],
                'environment' => ['select', [
                    'label' => 'Environment',
                    'description' => 'Test uses the registrar sandbox endpoint; production performs live operations.',
                    'multiOptions' => [
                        self::ENVIRONMENT_TEST => 'Test',
                        self::ENVIRONMENT_PRODUCTION => 'Production',
                    ],
                ]],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $this->getLog()->debug('Checking domain availability through Connect: ' . $domain->getName());

        return (bool) $this->callApi(
            'check availability for ' . $domain->getName(),
            fn (): bool => $this->getRegistrarClient()->available($domain->getName()),
        );
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        /*
        $this->getLog()->debug('Checking if domain can be transferred: ' . $domain->getName());
        */

        return true;
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $client = $this->getRegistrarClient();

        if (method_exists($client, 'updateNameservers')) {
            $nameservers = $this->getNameservers($domain);
            $result = $this->callApi(
                'update nameservers for ' . $domain->getName(),
                fn (): array => $client->updateNameservers($domain->getName(), $nameservers),
            );

            if (array_key_exists('successful', $result) && $result['successful'] !== true) {
                throw new Registrar_Exception('Connect could not update nameservers for :domain.', [
                    ':domain' => $domain->getName(),
                ]);
            }

            return true;
        }

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $client = $this->getRegistrarClient();
        $method = new ReflectionMethod($client, 'transfer');

        $this->callApi('transfer ' . $domain->getName(), function () use ($client, $domain, $method) {
            // Older releases use transfer(domain, authCode, contacts, years, nameservers).
            if ($method->getNumberOfParameters() >= 5) {
                return $client->transfer(
                    $domain->getName(),
                    (string) $domain->getEpp(),
                    $this->getContacts($domain),
                    $this->getPeriod($domain),
                    $this->getNameservers($domain),
                );
            }

            // Current releases use transfer(domain, authCode, purchasePrice).
            return $client->transfer($domain->getName(), (string) $domain->getEpp());
        });

        return true;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $details = $this->callApi(
            'get details for ' . $domain->getName(),
            fn () => $this->getRegistrarClient()->getDomain($domain->getName()),
        );

        $createdAt = $details->createdAt ?? null;
        $expiresAt = $details->expiresAt ?? null;
        $remoteNameservers = $details->nameservers ?? null;

        if ($createdAt instanceof DateTimeInterface) {
            $domain->setRegistrationTime($createdAt->getTimestamp());
        }

        if ($expiresAt instanceof DateTimeInterface) {
            $domain->setExpirationTime($expiresAt->getTimestamp());
        }

        if (is_array($remoteNameservers)) {
            $nameservers = array_values(array_filter(
                $remoteNameservers,
                static fn ($nameserver): bool => is_string($nameserver) && trim($nameserver) !== '',
            ));

            foreach (array_slice($nameservers, 0, 4) as $index => $nameserver) {
                $setter = 'setNs' . ($index + 1);
                $domain->{$setter}($nameserver);
            }
        }

        return $domain;
    }

    public function deleteDomain(Registrar_Domain $domain): bool
    {
        /*
        $this->getLog()->debug('Removing domain: ' . $domain->getName());
        */

        return true;
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $this->callApi(
            'register ' . $domain->getName(),
            fn () => $this->getRegistrarClient()->purchase(
                $domain->getName(),
                $this->getContacts($domain),
                $this->getPeriod($domain),
                $this->getNameservers($domain),
            ),
        );

        return true;
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->callApi(
            'renew ' . $domain->getName(),
            fn () => $this->getRegistrarClient()->renew($domain->getName(), $this->getPeriod($domain)),
        );

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        /*
        $this->getLog()->debug('Updating contact info: ' . $domain->getName());
        */

        return true;
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            /*
            OpenSRS privacy protection is not currently implemented by the
            Namingo Registrars adapter.
            */
            return true;
        }

        return (bool) $this->callApi(
            'enable privacy protection for ' . $domain->getName(),
            fn (): bool => $this->getRegistrarClient()->updateDomain(
                $domain->getName(),
                new NamingoUpdateDetails(privacy: true),
            ),
        );
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            /*
            OpenSRS privacy protection is not currently implemented by the
            Namingo Registrars adapter.
            */
            return true;
        }

        return (bool) $this->callApi(
            'disable privacy protection for ' . $domain->getName(),
            fn (): bool => $this->getRegistrarClient()->updateDomain(
                $domain->getName(),
                new NamingoUpdateDetails(privacy: false),
            ),
        );
    }

    public function getEpp(Registrar_Domain $domain): string
    {
        return (string) $this->callApi(
            'retrieve the transfer code for ' . $domain->getName(),
            fn (): string => $this->getRegistrarClient()->getAuthCode($domain->getName()),
        );
    }

    public function lock(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            /*
            OpenSRS registrar locking is not currently implemented by the
            Namingo Registrars adapter.
            */
            return true;
        }

        return (bool) $this->callApi(
            'lock ' . $domain->getName(),
            fn (): bool => $this->getRegistrarClient()->updateDomain(
                $domain->getName(),
                new NamingoUpdateDetails(locked: true),
            ),
        );
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        if ($this->config['registrar'] !== self::REGISTRAR_NAMECOM) {
            /*
            OpenSRS registrar unlocking is not currently implemented by the
            Namingo Registrars adapter.
            */
            return true;
        }

        return (bool) $this->callApi(
            'unlock ' . $domain->getName(),
            fn (): bool => $this->getRegistrarClient()->updateDomain(
                $domain->getName(),
                new NamingoUpdateDetails(locked: false),
            ),
        );
    }

    private function getRegistrarClient(): NamingoRegistrars
    {
        if ($this->registrarClient instanceof NamingoRegistrars) {
            return $this->registrarClient;
        }

        if ($this->config['registrar'] === self::REGISTRAR_NAMECOM) {
            $endpoint = $this->config['environment'] === self::ENVIRONMENT_PRODUCTION
                ? 'https://api.name.com'
                : 'https://api.dev.name.com';

            $constructor = new ReflectionMethod(NameCom::class, '__construct');
            $adapter = $constructor->getNumberOfParameters() >= 4
                ? new NameCom($this->config['username'], $this->config['api_key'], [], $endpoint)
                : new NameCom($this->config['username'], $this->config['api_key'], $endpoint);
        } else {
            $endpoint = $this->config['environment'] === self::ENVIRONMENT_PRODUCTION
                ? 'https://rr-n1-tor.opensrs.net:55443'
                : 'https://horizon.opensrs.net:55443';

            $constructor = new ReflectionMethod(OpenSRS::class, '__construct');
            $adapter = $constructor->getNumberOfParameters() >= 5
                ? new OpenSRS(
                    $this->config['api_key'],
                    $this->config['username'],
                    $this->config['password'],
                    [],
                    $endpoint,
                )
                : new OpenSRS(
                    $this->config['api_key'],
                    $this->config['username'],
                    $this->config['password'],
                    $endpoint,
                );
        }

        $this->registrarClient = new NamingoRegistrars($adapter);

        return $this->registrarClient;
    }

    /**
     * @return NamingoContact[]
     */
    private function getContacts(Registrar_Domain $domain): array
    {
        $registrant = $domain->getContactRegistrar();

        if (!$registrant instanceof Registrar_Domain_Contact) {
            throw new Registrar_Exception('Connect requires a registrant contact for :domain.', [
                ':domain' => $domain->getName(),
            ]);
        }

        $contacts = [
            $registrant,
            $domain->getContactAdmin() ?? $registrant,
            $domain->getContactTech() ?? $registrant,
            $domain->getContactBilling() ?? $registrant,
        ];

        return array_map(fn (Registrar_Domain_Contact $contact): NamingoContact => new NamingoContact(
            (string) $contact->getFirstName(),
            (string) $contact->getLastName(),
            $this->formatPhone($contact),
            (string) $contact->getEmail(),
            (string) $contact->getAddress1(),
            (string) $contact->getAddress2(),
            (string) $contact->getAddress3(),
            (string) $contact->getCity(),
            (string) $contact->getState(),
            strtoupper((string) $contact->getCountry()),
            (string) $contact->getZip(),
            (string) $contact->getCompany(),
            (string) $contact->getName(),
        ), $contacts);
    }

    /**
     * @return string[]
     */
    private function getNameservers(Registrar_Domain $domain): array
    {
        $nameservers = [
            $domain->getNs1(),
            $domain->getNs2(),
            $domain->getNs3(),
            $domain->getNs4(),
        ];

        $nameservers = array_map(
            static fn ($nameserver): string => strtolower(rtrim(trim((string) $nameserver), '.')),
            $nameservers,
        );

        return array_values(array_unique(array_filter(
            $nameservers,
            static fn (string $nameserver): bool => $nameserver !== '',
        )));
    }

    private function getPeriod(Registrar_Domain $domain): int
    {
        return max(1, (int) ($domain->getRegistrationPeriod() ?? 1));
    }

    private function formatPhone(Registrar_Domain_Contact $contact): string
    {
        $number = trim((string) $contact->getTel());

        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '+')) {
            return '+' . preg_replace('/\D+/', '', substr($number, 1));
        }

        $countryCode = preg_replace('/\D+/', '', (string) $contact->getTelCc());
        $subscriber = preg_replace('/\D+/', '', $number);

        return $countryCode !== '' ? '+' . $countryCode . $subscriber : $subscriber;
    }

    private function requireSetting(string $key, string $label): void
    {
        if (trim((string) $this->config[$key]) === '') {
            throw new Registrar_Exception('The Connect registrar is not fully configured. Please configure :missing.', [
                ':missing' => $label,
            ], 3001);
        }
    }

    private function callApi(string $operation, callable $callback)
    {
        try {
            return $callback();
        } catch (Registrar_Exception $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $registrar = $this->config['registrar'] === self::REGISTRAR_NAMECOM ? 'Name.com' : 'OpenSRS';
            $message = sprintf('Connect (%s) failed to %s: %s', $registrar, $operation, $exception->getMessage());
            $this->getLog()->error($message);

            $code = $exception->getCode();
            throw new Registrar_Exception($message, null, is_int($code) ? $code : 0);
        }
    }
}