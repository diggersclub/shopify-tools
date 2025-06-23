<?php

use Illuminate\Support\Facades\Http;

class ShopifyStorefront
{
    public string $accessToken;

    public string $domain;

    public string $version;

    public function __construct(string $accessToken, string $domain, string $version = '2024-04')
    {
        $this->accessToken = $accessToken;
        $this->domain = $domain;
        $this->version = $version;
    }

    public function executeGraphQL($query, $variables)
    {
        $url = "https://{$this->domain}/api/{$this->version}/graphql.json";

        $data = [
            'query' => $query,
        ];

        if ($variables) {
            $data['variables'] = $variables;
        }

        // dump($url, $data);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken,
            'Content-Type' => 'application/json',
        ])->post(
            $url,
            $data
        );

        if (!$response->successful()) {
            throw new Exception('Shopify Storefront Error: ' . $response->body());
        }

        $json = $response->json();

        if (isset($json['errors'])) {
            throw new Exception('Shopify Storefront Error: ' . data_get($json, 'errors.0.message'));
        }

        $data = data_get($json, 'data');

        return $data;
    }

    /**
     * - use credits to authenticate against shopify store api
     * - use the access token to get the dcustomer details - and id
     * - get meta where membership details are
     * - update or create user
     * - login that user
     */
    public function login(string $email, string $password)
    {
        $accessToken = $this->getAccessToken($email, $password);
        session(['customer_access_token' => $accessToken]);

        $data = $this->getCustomerDetails($accessToken);

        $customerId = str($data['id'])->afterLast('/');
        $email = $data['email'] ?? null;

        $adminService = new ShopifyAdminUserService;

        $user = $adminService->getOrCreateUserFromIdAndEmail($customerId, $email);
        $meta = $adminService->getMetaForId($customerId);

        $this->applyChangesToUser($user, $customerId, $data, $meta);

        Auth::login($user);
    }

    public function getAccessToken(string $email, string $password): string
    {
        $data = $this->executeGraphQL(
            '
                mutation customerAccessTokenCreate($input: CustomerAccessTokenCreateInput!) {
                    customerAccessTokenCreate(input: $input) {
                        customerAccessToken {
                            accessToken
                            expiresAt
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
            ',
            [
                'input' => [
                    'email' => $email,
                    'password' => $password,
                ],
            ]
        );

        // failed
        if (!isset($data['data']['customerAccessTokenCreate']['customerAccessToken'])) {
            $message = $data['data']['customerAccessTokenCreate']['userErrors'][0]['message'] ?? 'Login failed.';
            session(['customer_access_token' => null]);
            throw new Exception($message);
        }

        // success
        return $data['data']['customerAccessTokenCreate']['customerAccessToken']['accessToken'];
    }

    public function getCustomerDetails($accessToken)
    {
        $data = $this->executeGraphQL(
            '
                query ($customerAccessToken: String!) {
                    customer(customerAccessToken: $customerAccessToken) {
                        id
                        firstName
                        lastName
                        email
                        phone
                        addresses(first: 5) {
                            edges {
                                node {
                                    address1
                                    city
                                    country
                                }
                            }
                        }
                    }
                }
            ',
            [
                'customerAccessToken' => $accessToken,
            ]
        );

        // error
        if (!isset($data['data']['customer'])) {
            $message = $data['errors'][0]['message'] ?? 'Unable to fetch customer details.';
            throw new Exception($message);
        }

        // success
        return $data['data']['customer'];
    }
}
