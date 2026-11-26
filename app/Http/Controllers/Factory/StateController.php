<?php

namespace App\Http\Controllers\Factory;

use Illuminate\Http\JsonResponse;

class StateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return $this->state();
    }
}
