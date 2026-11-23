<?php

namespace Modules\ClientMobileApp\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ClientMobileApp\App\Models\MobileLegalDocument;

class LegalDocumentController extends Controller
{
    public function index(): JsonResponse
    {
        $documents = MobileLegalDocument::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->keyBy('document_key')
            ->map(fn (MobileLegalDocument $document) => [
                'key' => $document->document_key,
                'title' => $document->title,
                'html_content' => $document->html_content,
                'version' => $document->version,
            ]);

        return response()->json(['documents' => $documents]);
    }
}
