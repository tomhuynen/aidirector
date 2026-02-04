<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Widgets;

use App\Support\Widgets\WidgetIdentifier;
use Illuminate\Http\Request;

class ActionController
{
    public function execute(Request $request)
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'action' => ['required', 'string'],
            'params' => ['array'],
        ]);

        $widget = WidgetIdentifier::make($request->input('identifier'));

        $result = $widget->execute(
            action: $request->input('action'),
            params: $request->input('params', []),
        );

        return [
            'result' => $result,
        ];
    }
}
