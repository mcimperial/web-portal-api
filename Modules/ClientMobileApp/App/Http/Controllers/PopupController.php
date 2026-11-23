<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ClientMobileApp\App\Models\MobilePopup;
use Modules\ClientMobileApp\App\Models\MobilePopupMessage;

class PopupController extends Controller
{
    public function index(): JsonResponse
    {
        $popups = MobilePopup::query()
            ->currentlyActive()
            ->whereHas('messages')
            ->with('messages')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MobilePopup $popup) => [
                'id' => $popup->id,
                'title' => $popup->title,
                'show_decline_button' => $popup->show_decline_button,
                'accept_label' => $popup->accept_label,
                'decline_label' => $popup->decline_label,
                'messages' => $popup->messages->map(fn (MobilePopupMessage $message) => [
                    'id' => $message->id,
                    'html_content' => $message->html_content,
                    'image_url' => $message->image_url,
                ])->values(),
            ])
            ->values();

        return response()->json(['popups' => $popups]);
    }
}
