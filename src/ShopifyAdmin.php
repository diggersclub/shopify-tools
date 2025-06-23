<?php

use Illuminate\Support\Facades\Http;

class ShopifyAdmin
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
        $url = "https://{$this->domain}/admin/api/{$this->version}/graphql.json";

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
            throw new Exception('Shopify Admin Error: ' . $response->body());
        }

        $json = $response->json();

        if (isset($json['errors'])) {
            throw new Exception('Shopify Admin Error: ' . data_get($json, 'errors.0.message'));
        }

        $data = data_get($json, 'data');

        return $data;
    }

    public function searchCustomers($search)
    {
        $query = <<<'GRAPHQL'
            query ($search: String!) {
                customers(first: 50, query: $search) {
                    edges {
                        node {
                            id
                            firstName
                            lastName
                            email
                            phone
                            tags
                            note
                            defaultAddress {
                                address1
                                city
                                province
                                country
                                zip
                            }
                            metafields (first: 100) {
                                nodes {
                                    id
                                    key
                                    value
                                }
                            }
                        }
                    }
                    pageInfo {
                        hasNextPage
                    }
                }
            }
        GRAPHQL;

        $response = $this->executeGraphQL(
            $query,
            ['search' => $search]
        );

        // refactor

        $result = [];

        $rows = data_get($response, 'customers.edges', []);
        foreach ($rows as $row) {
            $result[] = $this->refactorCustomerRecord($row['node']);
        }

        $pageInfo = data_get($response, 'customers.pageInfo');

        return [$result,  $pageInfo];
    }

    public function getCustomer($customerId)
    {
        $query = <<<'GRAPHQL'
            query ($customerId: ID!) {
                customer (id: $customerId) {
                    id
                    firstName
                    lastName
                    email
                    phone
                    tags
                    note
                    defaultAddress {
                        address1
                        city
                        province
                        country
                        zip
                    }
                    metafields (first: 100) {
                        nodes {
                            id
                            key
                            value
                        }
                    }
                }
            }
        GRAPHQL;

        $response = $this->executeGraphQL(
            $query,
            ['customerId' => "gid://shopify/Customer/{$customerId}"]
        );

        $result = $this->refactorCustomerRecord($response['customer']);

        return [$result, null];
    }

    public function refactorCustomerRecord($record)
    {
        if (!$record) {
            return null;
        }

        $result = [
            'id' => data_get($record, 'id'),
            'firstName' => data_get($record, 'firstName'),
            'lastName' => data_get($record, 'lastName'),
            'email' => data_get($record, 'email'),
            'phone' => data_get($record, 'phone'),
            'note' => data_get($record, 'note'),
            'tags' => data_get($record, 'tags'), // array
        ];

        $metafields = data_get($record, 'metafields');
        if ($metafields) {
            $result['meta'] = [];

            foreach ($metafields['nodes'] as $metafield) {
                $result['meta'][$metafield['key']] = $metafield['value'];
            }
        }

        return $result;
    }
}
