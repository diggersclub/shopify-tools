<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class Skio
{
    public string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function execute($query, $variables)
    {
        $url = 'https://graphql.skio.com/v1/graphql';

        $data = [
            'query' => $query,
        ];

        if ($variables) {
            $data['variables'] = $variables;
        }

        // dump($url, $data);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'authorization' => 'API ' . $this->apiKey,
        ])->post(
            $url,
            $data
        );

        if (!$response->successful()) {
            throw new Exception('Skio API Error: ' . $response->body());
        }

        $json = $response->json();

        if (isset($json['errors'])) {
            throw new Exception('Skio API Error: ' . data_get($json, 'errors.0.message'));
        }

        $data = data_get($json, 'data');

        return $data;
    }

    public function getSubscriptionsByCustomerId($customerId)
    {
        $query = <<<'GRAPHQL'
            query ($platformId: String!) {
                Subscriptions (
                    where: {
                        StorefrontUser: {platformId: {_eq: $platformId}},
                        nextBillingDate: {_is_null: false}
                    }, 
                    order_by: {createdAt: desc}
                ) {
                    id
                    platformId
                    createdAt
                    cancelledAt
                    cyclesCompleted
                    deliveryPrice
                    nextBillingDate
                    StorefrontUser {
                        email
                        platformId
                    }
                    SubscriptionLines(where: {removedAt: {_is_null: true}}) {
                        priceWithoutDiscount
                        ProductVariant {
                            title
                            Product {
                                title
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        return $this->execute(
            $query,
            ['platformId' => "gid://shopify/Customer/{$customerId}"]
        );
    }

    public function getStorefrontUserByCustomerId($customerId)
    {
        $query = <<<'GRAPHQL'
            query (
                    $platformId: String
                ) {
                StorefrontUsers (
                    where: {platformId: {_eq: $platformId}}
                ) {
                    id
                    firstName
                    lastName
                    email
                    phoneNumber
                    platformId
                    PaymentMethods {
                        id
                        lastDigits
                        platformId
                    }
                    ShippingAddresses {
                        id
                        address1
                        address2
                        city
                        province
                        zip
                        country
                        platformId
                    }   
                }
            }
        GRAPHQL;

        $response = $this->execute(
            $query,
            ['platformId' => "gid://shopify/Customer/{$customerId}"]
        );

        return data_get($response, 'StorefrontUsers.0', null);
    }

    public function getProductVariants()
    {
        $query = <<<'GRAPHQL'
            query {
                SellingPlans {
                    id
                    name
                    option
                    BillingPolicy {
                        id
                        interval
                        intervalCount
                    }
                    SellingPlanGroup {
                        id
                        SellingPlanGroupResources {
                            id
                            ProductVariant {
                                id
                                title
                                price
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $response = $this->execute($query, null);

        // refactor

        $result = [];

        $plans = data_get($response, 'SellingPlans', []);
        foreach ($plans as $plan) {
            $options = data_get($plan, 'SellingPlanGroup.SellingPlanGroupResources');
            $variants = [];

            foreach ($options as $optionRow) {
                $variants[data_get($optionRow, 'ProductVariant.id')] = [
                    'name' => data_get($optionRow, 'ProductVariant.title'),
                    'price' => data_get($optionRow, 'ProductVariant.price'),
                ];
            }

            $planData = [
                'name' => data_get($plan, 'name'),
                'interval' => data_get($plan, 'BillingPolicy.interval'),
                'intervalCount' => data_get($plan, 'BillingPolicy.intervalCount'),
                'variants' => $variants,
            ];

            $result[$plan['id']] = $planData;
        }

        return $result;
    }

    public function createSubscription(
        string $storefrontUserId, // 49790499-f1e3-47a6-ac57-c8b48867cbdc
        string $addressId, // 76b879a4-455c-4431-8a24-44541e8d3c6f
        string $paymentMethodId, // b467ae6b-9e19-48cb-9203-d41b8e2c5407
        float $price, // 59.00
        string $variantId, // 8f72ee4d-fb42-4dfe-903c-ad34f72e3bc1
        string $interval,
        int $intervalCount,
        Carbon $nextBillingDate,
    ) {
        $mutation = <<<'GRAPHQL'
            mutation createSubscription($input: CreateSubscriptionInput!) {
                createSubscription(input: $input) {
                    id
                    platformId
                }
            }
        GRAPHQL;

        $variables = [
            'input' => [
                'storefrontUserId' => $storefrontUserId,
                'addressId' => $addressId,
                'paymentMethodId' => $paymentMethodId,
                'subscriptionLines' => [
                    [
                        'price' => $price,
                        'quantity' => 1,
                        'variantId' => $variantId,
                    ],
                ],
                'nextBillingDate' => $nextBillingDate->toDateString(),
                'subscriptionType' => 'SUBSCRIBE_AND_SAVE',
                'billingPolicyInfo' => [
                    'interval' => $interval,
                    'intervalCount' => $intervalCount,
                ],
            ],
        ];

        // dump(json_encode($variables, JSON_PRETTY_PRINT));

        return $this->execute(
            $mutation,
            $variables,
        );
    }

    public function createAddress(
        $storefrontUserId,
        $firstName,
        $lastName,
        $address1,
        $address2,
        $city,
        $province,
        $zip,
    ) {
        $mutation = <<<'GRAPHQL'
            mutation ($input: CreateAddressForStorefrontUserInput!) {
                addAddressForStorefrontUser (input: $input) {
                    id
                    platformId
                }
            }
        GRAPHQL;

        return $this->execute(
            $mutation,
            [
                'input' => [
                    'storefrontUserId' => $storefrontUserId,
                    'address1' => $address1,
                    'address2' => $address2,
                    'city' => $city,
                    'province' => $province,
                    'zip' => $zip,
                    'country' => 'AU',
                    'firstName' => $firstName,
                    'lastName' => $lastName,
                ],
            ],
        );
    }

    public function createPaymentMethod(
        $storefrontUserId,
        $firstName,
        $lastName,
    ) {
    }
}
