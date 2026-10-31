<?php

namespace App\Http\Controllers;

use App\Http\Responses\SpaPage;
use App\MarketKnowledge\MarketOperations;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

final class MarketOperationsController
{
    public function index(Request $request, MarketOperations $operations): SpaPage
    {
        abort_if(array_diff(array_keys($request->query()), ['cursor']) !== [], 422);
        $input = $request->validate(['cursor' => ['sometimes', 'uuid']]);
        try {
            $data = $operations->read($request, $input['cursor'] ?? null);
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? '') !== '42501') {
                throw $error;
            }
            abort(403);
        }

        return new SpaPage(SpaPage::needsData($request) ? $data : []);
    }
}
