<?php

declare(strict_types=1);

namespace SelcomDevelopers\Selcom\Request;

/**
 * The `billing` or `shipping` group of a checkout order. It is signed and sent as dotted keys
 * (`billing.firstname`). Card payments are refused without full billing details.
 */
final class Address
{
    public readonly string $firstname;
    public readonly string $lastname;
    public readonly string $address1;
    public readonly ?string $address2;
    public readonly string $city;
    public readonly string $stateOrRegion;
    public readonly string $postcodeOrPobox;
    public readonly string $country;
    public readonly string $phone;

    /**
     * @param string $country ISO 3166-1 alpha-2, for example TZ
     */
    public function __construct(
        string $firstname,
        string $lastname,
        string $address1,
        string $city,
        string $stateOrRegion,
        string $postcodeOrPobox,
        string $country,
        string $phone,
        ?string $address2 = null,
    ) {
        $this->firstname = Check::required('firstname', $firstname);
        $this->lastname = Check::required('lastname', $lastname);
        $this->address1 = Check::required('address_1', $address1);
        $this->address2 = Check::optional('address_2', $address2);
        $this->city = Check::required('city', $city);
        $this->stateOrRegion = Check::required('state_or_region', $stateOrRegion);
        $this->postcodeOrPobox = Check::required('postcode_or_pobox', $postcodeOrPobox);
        $this->country = Check::required('country', $country);
        $this->phone = Check::required('phone', $phone);
    }

    /** @return array<string, string> */
    public function toParams(): array
    {
        $params = [
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'address_1' => $this->address1,
        ];
        if ($this->address2 !== null) {
            $params['address_2'] = $this->address2;
        }

        return $params + [
            'city' => $this->city,
            'state_or_region' => $this->stateOrRegion,
            'postcode_or_pobox' => $this->postcodeOrPobox,
            'country' => $this->country,
            'phone' => $this->phone,
        ];
    }
}
