<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FrontendContentController extends Controller
{
    public function home(): JsonResponse
    {
        if (! filter_var(env('FRONTEND_API_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new NotFoundHttpException();
        }

        return response()->json([
            'hero' => [
                'kicker' => 'Jorsas',
                'title' => 'Mobile Apps & Website Development',
                'subtitle' => "We have a team of experts across different strata of Software Development and would provide you with the clear insight your business needs to create an amazing future",
                'ctaPrimary' => 'Learn More',
                'ctaSecondary' => 'Our Services',
            ],
            'capabilities' => [
                [
                    'title' => 'WEB DEVELOPMENT',
                    'description' => "Over the years, we've made a reputation for building websites that look great & are easy-to-use in 7 days. Just think about the website and allow us develop it.",
                    'image' => '/images/sections/web-development.jpg',
                ],
                [
                    'title' => 'MOBILE APP DEVELOPMENT',
                    'description' => 'At Jorsas, our skilled Mobile Developers are always available to handle projects using modern technologies (Flutter) to develop mobile applications accessible on IOS and Android platforms.',
                    'image' => '/images/sections/mobile-app.jpg',
                ],
                [
                    'title' => 'UI/UX DESIGNS',
                    'description' => 'Understanding the human experience is essential for creating useful and effective products. At Jorsas, our designers enjoy using their skill sets to empower people to accomplish their goals. We create digital experiences that make life easier.',
                    'image' => '/images/sections/ui-ux.jpg',
                ],
                [
                    'title' => 'API DEVELOPMENT',
                    'description' => "An application programming interface, or API, enables companies to open up their applications' data and functionality to external third-party developers and business partners, or to departments within their companies.",
                    'image' => '/images/sections/api.jpg',
                ],
            ],
            'showcase' => [
                [
                    'title' => 'Client Base',
                    'text' => '120+ satisfied clients across real estate and tech sectors',
                    'accent' => 'blue',
                    'image' => '/images/sections/showcase-client-base.jpg',
                ],
                [
                    'title' => 'Customer Retention',
                    'text' => '80% repeat business rate',
                    'accent' => 'green',
                    'image' => '/images/sections/showcase-customer-retention.jpg',
                ],
                [
                    'title' => 'Business Experience',
                    'text' => 'With Over 25 Years Of Experience, We Have Crafted Thousands Of Strategic Discovery Process That Enables Us To Peel Back Which Enable Us To Understand.',
                    'accent' => 'pink',
                    'image' => '/images/sections/showcase-business-experience.jpg',
                ],
            ],
            'sponsors' => [
                [
                    'name' => 'Noirtech',
                    'logo' => '/images/sponsors/noirtech.png',
                    'href' => 'https://noirtech.io',
                ],
                [
                    'name' => 'ECR',
                    'logo' => '/images/sponsors/ecr.png',
                ],
                [
                    'name' => 'Payitmonthly',
                    'logo' => '/images/sponsors/payitmonthly2023.png',
                    'href' => 'https://naturewave.com/',
                ],
            ],
        ]);
    }
}
