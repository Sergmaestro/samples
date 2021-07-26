<?php


namespace App\Helpers;


use App\Models\ModelYear;
use App\Models\Vehicle;
use App\Services\ReviewService;
use Carbon\Carbon;

/**
 * Class ldJsonHelper
 *
 * generates ld+json schema based on Model Year|Vehicle description and data
 *
 * @package App\Helpers
 */
class LdJsonHelper
{

    /**
     * @var ReviewService
     */
    private $reviewService;

    /**
     * LdJsonHelper constructor.
     * @param ReviewService $reviewService
     */
    public function __construct(ReviewService $reviewService)
    {
        $this->reviewService = $reviewService;
    }

    /**
     * @param string $type
     * @param ModelYear $modelYear
     * @return array
     */
    public function modelYearBreadCrumbsJSON(string $type, ModelYear $modelYear)
    {
        $ldJSONArray = [
            '@context' => 'http://schema.org',
            '@type' => $type,
        ];

        $ldJSONArray['itemListElement'] = $this->breadCrumbs([
            [
                'url' => url(''),
                'name' => 'Home'
            ],
            [
                'url' => url('/search'),
                'name' => 'New Cars'
            ],
            [
                'url' => url('/search/' . strtolower($modelYear->make->slug)),
                'name' => $modelYear->make->name
            ]
        ]);

        return $ldJSONArray;
    }

    /**
     * @param string $type
     * @param Vehicle $vehicle
     * @return array
     */
    public function vehicleBreadCrumbsJSON(string $type, Vehicle $vehicle)
    {
        $modelYear = $vehicle->modelYear;
        $makeSlug = strtolower($modelYear->make->slug);

        $ldJSONArray = [
            '@context' => 'http://schema.org',
            '@type' => $type,
        ];
        $ldJSONArray['itemListElement'] = $this->breadCrumbs([
            [
                'url' => url(''),
                'name' => 'Home'
            ],
            [
                'url' => url('/search'),
                'name' => 'New Cars'
            ],
            [
                'url' => url('/search/' . $makeSlug),
                'name' => $modelYear->make->name
            ],
            [
                'url' => url($makeSlug . '/' .
                    $modelYear->year . '/' .
                    $modelYear->model->slug . '/' .
                    str_slug_nc($vehicle->name)
                ),
                'name' => $modelYear->name
            ],
        ]);

        return $ldJSONArray;
    }

    /**
     * @param string $type
     * @param ModelYear $modelYear
     * @param $images
     * @param $similar
     * @param $reviews
     * @param $ratings
     * @return array
     */
    public function modelYearProductJSON(string $type, ModelYear $modelYear, $images, $similar, $reviews, $ratings)
    {
        $ldJSONArray = [
            '@context' => 'http://schema.org',
            '@type' => $type,
        ];
        $modelYearImagesURLsArray = $this->modelYearImagesURLs($modelYear, $images);
        $vehicles = $modelYear->vehicles;
        $makeName = $modelYear->make->name;
        $pageURL = url($modelYear->make->slug . '/' . $modelYear->year . '/' . $modelYear->model->slug);
        $vehicles->load(['vehicleType', 'transmission']);

        /** Body Types */
        $bodyTypes = $vehicles->pluck('vehicleType.name')
            ->unique();

        /** Fuel Types */
        $fuelTypes = $vehicles->pluck('fuel_type')
            ->unique()->map(function ($fuelType) {
                return ucwords($fuelType);
            });

        /** Seats Capacity */
        $seats = $vehicles->pluck('seats')->unique();

        /** Transmission */
        $transmission = $vehicles
            ->pluck('transmission.name')->unique()
            ->reject(function ($value) { return $value == null; });

        /** Engine */
        $engineCapacitySorted = $vehicles->pluck('engine_capacity')->unique()->sort()->values();
        $engineCapacity = $engineCapacitySorted->map(function ($transmission) {
            return [
                '@type' => 'EngineSpecification',
                'name' => $transmission . ' cc'
            ];
        });

        $ldJSONArray['additionalType'] = 'Car';
        $ldJSONArray['name'] = $modelYear->name;
        $ldJSONArray['brand'] = [
            '@type' => 'Thing',
            'name' => $makeName
        ];
        $ldJSONArray['model'] = $modelYear->model->name;
        $ldJSONArray['bodyType'] = count($bodyTypes) > 1 ? $bodyTypes : $bodyTypes->first();
        $ldJSONArray['fuelType'] = count($fuelTypes) > 1 ? $fuelTypes : $fuelTypes->first();
        $ldJSONArray['seatingCapacity'] = count($seats) > 1 ? $seats->sort()->values() : $seats->first();
        $ldJSONArray['vehicleTransmission'] = count($transmission) > 1
            ? $transmission->sort()->values()
            : $transmission->first();

        $ldJSONArray['mainEntityOfPage'] = $pageURL;
        $ldJSONArray['url'] = $pageURL;
        $ldJSONArray['image'] = $modelYearImagesURLsArray;
        if ($modelYear->description) {
            $ldJSONArray['description'] = $modelYear->description;
        }
        $ldJSONArray['vehicleEngine'] = count($engineCapacity) > 1 ? $engineCapacity : $engineCapacity->first();
        $ldJSONArray['manufacturer'] = [
            '@type' => 'Organization',
            'name' => $makeName
        ];

        /** Offer + Prices */
        if (!$modelYear->calculated_price_upon_request) {
            $ldJSONArray['offers'] = [
                '@type' => 'AggregateOffer',
                'additionalType' => 'Offer',
                'priceCurrency' => config('newcar.currency_international'),
                'lowPrice' => round($vehicles->min('msrp')),
                'highPrice' => round($vehicles->max('msrp')),
                'offerCount' => $vehicles->count(),
                'itemCondition' => 'http://schema.org/NewCondition'
            ];
        }

        /** Similar Cars */
        if (isset($similar) && count($similar)) {
            $ldJSONArray['isSimilarTo'] = $similar->take(5)->map(function ($similarModel) {
                $similarSchema = [
                    '@type' => 'Product',
                    'url' => url(
                        $similarModel->make->slug .
                        '/' . $similarModel->year .
                        '/' . $similarModel->model->slug
                    ),
                    'image' => recachedAsset($similarModel->search_image_thumb_url),
                    'name' => $similarModel->name,
                    'brand' => [
                        '@type' => 'Thing',
                        'name' => $similarModel->make->name
                    ],
                    'model' => $similarModel->model->name,
                    'offers' => [
                        '@type' => 'AggregateOffer',
                        'additionalType' => 'Offer',
                        'priceCurrency' => config('newcar.currency_international'),
                        'lowPrice' => round($similarModel->vehicles->min('msrp')),
                        'highPrice' => round($similarModel->vehicles->max('msrp')),
                    ],
                ];

                $reviewRatings = ModelYear::getEsModelYearById($similarModel->id)['review_ratings'] ?? null;

                if ($reviewRatings) {
                    $similarSchema['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => $reviewRatings['overal_rating']['avg'],
                        'reviewCount' => $reviewRatings['total'],
                    ];
                }

                return $similarSchema;
            });
        }

        /** Reviews & ratings */
        if (count($reviews) && $ratings['overal_rating']['avg'] >= 3) {
            $ldJSONArray['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $ratings['overal_rating']['avg'],
                'reviewCount' => $ratings['total'],
            ];

            $ldJSONArray['review'] = $reviews->map(function ($review) {
                return [
                    '@type' => 'Review',
                    'author' => [
                        '@type' => 'Person',
                        'name' => trim($review->name)
                    ],
                    'datePublished' => Carbon::parse($review->created_at)->toW3cString(),
                    'name' => trim($review->title),
                    'reviewBody' => trim($review->details),
                    'reviewRating' => [
                        '@type' => 'Rating',
                        'ratingValue' => $review->overal_rating
                    ]
                ];
            });
        }

        return $ldJSONArray;
    }

    /**
     * @param string $type
     * @param Vehicle $vehicle
     * @param $images
     * @param $country_id
     * @return array
     */
    public function vehicleProductJSON(string $type, Vehicle $vehicle, $images, $country_id)
    {
        $ldJSONArray = [
            '@context' => 'http://schema.org',
            '@type' => $type,
        ];
        $modelYear = $vehicle->modelYear;
        $variant = $vehicle->variant;
        $variantFullName = $modelYear->name .' '. $variant;
        $modelYearImagesURLsArray = $this->modelYearImagesURLs($modelYear, $images);

        $modelYear->load([
            'vehicles' => function ($query) use ($country_id, $vehicle) {
                $query->where('status', true)
                    ->where('id', '<>', $vehicle->id)
                    ->whereHas('vehicleType', function ($query) use ($country_id) {
                        $query->whereHas('country_status', function ($query) use ($country_id) {
                            $query->where('country_id', $country_id)
                                ->where('active', true);
                        });
                    });
            }
        ]);

        $vehicles = $modelYear->vehicles->load(['modelYear', 'modelYear.make', 'modelYear.model']);
        $makeName = $modelYear->make->name;

        $modelYearPageURL = url(
            $modelYear->make->slug . '/' .
            $modelYear->year . '/' .
            $modelYear->model->slug
        );

        $vehiclePageURL = $modelYearPageURL . '/' . str_slug_nc($vehicle->variant);

        $ldJSONArray['additionalType'] = 'Car';
        $ldJSONArray['name'] = $variantFullName;
        $ldJSONArray['brand'] = [
            '@type' => 'Thing',
            'name' => $makeName
        ];
        $ldJSONArray['model'] = $modelYear->model->name;
        $ldJSONArray['bodyType'] = $vehicle->vehicleType->name;
        $ldJSONArray['fuelType'] = $vehicle->fuel_type;
        $ldJSONArray['seatingCapacity'] = $vehicle->seats;
        $ldJSONArray['vehicleTransmission'] = $vehicle->transmission->name;
        $ldJSONArray['mainEntityOfPage'] = $vehiclePageURL;
        $ldJSONArray['url'] = $vehiclePageURL;
        $ldJSONArray['image'] = $modelYearImagesURLsArray;

        if ($modelYear->description) {
            $ldJSONArray['description'] = __('public.seo.variant_details.meta_description', [
                'variant_name' => $modelYear->name . ' '. $variant
            ]);
        }
        $ldJSONArray['vehicleEngine'] = [
            '@type' => 'EngineSpecification',
            'name' => $vehicle->engine_capacity . ' cc'
        ];

        $ldJSONArray['manufacturer'] = [
            '@type' => 'Organization',
            'name' => $makeName
        ];

        /** Offer + Prices */
        if (!$modelYear->calculated_price_upon_request) {
            $ldJSONArray['offers'] = [
                '@type' => 'Offer',
                'priceCurrency' => config('newcar.currency_international'),
                'price' => round($vehicle->msrp),
                'itemCondition' => 'http://schema.org/NewCondition'
            ];
        }

        /** Similar Cars */
        if (count($vehicles)) {
            $ldJSONArray['isSimilarTo'] = $vehicles->map(function ($similarVehicle) use ($modelYearPageURL) {
                return [
                    '@type' => 'Product',
                    'url' => $modelYearPageURL . '/' . str_slug_nc($similarVehicle->variant),
                    'image' => recachedAsset($similarVehicle->modelYear->search_image_thumb_url),
                    'name' => $similarVehicle->modelYear->name . ' ' . $similarVehicle->variant,
                    'brand' => [
                        '@type' => 'Thing',
                        'name' => $similarVehicle->modelYear->make->name
                    ],
                    'model' => $similarVehicle->modelYear->model->name,
                    'offer' => [
                        '@type' => 'Offer',
                        'priceCurrency' => config('newcar.currency_international'),
                        'price' => round($similarVehicle->msrp),
                        'itemCondition' => 'http://schema.org/NewCondition'
                    ],
                ];
            });
        }

        return $ldJSONArray;
    }

    /**
     * @param ModelYear $modelYear
     * @param $images
     * @return array
     */
    private function modelYearImagesURLs(ModelYear $modelYear, $images)
    {
        $imagePrefix = '?rand=' . Carbon::parse($modelYear->updated_at)->timestamp;

        $imageURLsArray = [
            recachedAsset($modelYear->search_image_thumb_url) // Search image
        ];

        if ($modelYear->banner_image_original_url) { // Banner image
            $imageURLsArray[] = recachedAsset($modelYear->banner_image_original_url) . $imagePrefix;
        }

        if (!empty($images)) { // Exterior + interior
            foreach ($images as $image) {
                if (!empty($image['url_large'])) $imageURLsArray[] = recachedAsset($image['url_large']);
            }
        }

        // Colours
        if (isset($modelYear->modelYearColours) && count($modelYear->modelYearColours)) {
            foreach ($modelYear->modelYearColours as $colour) {
                $imageURLsArray[] = recachedAsset($colour->url_large);
            }
        }

        return $imageURLsArray;
    }

    /**
     * @param array $urls
     * @return array
     */
    private function breadCrumbs($urls = [])
    {
        array_walk( $urls, function(&$link, $key) {
            $link = [
                '@type' => 'ListItem',
                'name' => $link['name'],
                'position' => ++$key,
                'item' => [
                    '@id' => $link['url']
                ]
            ];
        });

        return $urls;
    }
}