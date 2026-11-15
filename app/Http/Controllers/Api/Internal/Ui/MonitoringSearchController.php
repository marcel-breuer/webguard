<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Internal\Ui;

use App\Http\Controllers\Controller;
use App\Http\Requests\InternalUi\MonitoringSearchRequest;
use App\Models\User;
use App\Queries\MonitoringSearchQuery;
use Illuminate\Http\JsonResponse;

class MonitoringSearchController extends Controller
{
    public function __invoke(MonitoringSearchRequest $request, MonitoringSearchQuery $query): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $query->for($user, $request->search())])
            ->header('Cache-Control', 'private, no-store');
    }
}
