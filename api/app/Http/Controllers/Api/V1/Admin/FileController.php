<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileController extends Controller
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'application/pdf'];

    public function store(Request $request)
    {
        abort_unless($request->user()->can('settings.update') || $request->user()->can('branding.update'), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(self::MAX_BYTES / 1024)],
            'kind' => ['required', 'in:logo,bus_photo,document,ticket_asset'],
        ]);

        $file = $request->file('file');
        $mime = $file->getMimeType(); // server-side sniff — never trust client names

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            return response()->json(['message' => 'Unsupported file type.'], 422);
        }

        $ext = match ($mime) {
            'image/png' => 'png', 'image/jpeg' => 'jpg',
            'image/webp' => 'webp', 'application/pdf' => 'pdf',
        };

        $path = sprintf('tenants/%d/%s/%s.%s', tenant_id(), $request->kind, Str::uuid(), $ext);
        Storage::disk('public')->put($path, $file->getContent());

        $record = TenantFile::create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => substr($file->getClientOriginalName(), 0, 190),
            'mime' => $mime,
            'size' => $file->getSize(),
            'kind' => $request->kind,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($record, 201);
    }
}
