<?php

namespace App\Http\Controllers;

use App\Models\OutreachAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/*
 * The PDF library's upload, in 2 MB pieces: Vercel turns away any request over
 * 4.5 MB, and a portfolio is usually bigger than that. The browser announces the
 * file, sends each piece, then asks for it to be checked and made available.
 */
class OutreachAttachmentsController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filename' => ['required', 'string', 'max:200', 'regex:/\.pdf$/i'],
            'size' => ['required', 'integer', 'min:1', 'max:'.OutreachAttachment::MaxBytes],
        ], [
            'size.max' => __('That PDF is over 15 MB. Many mail servers bounce emails that large; export a smaller version.'),
            'filename.regex' => __('Only PDFs can be attached.'),
        ]);

        $attachment = OutreachAttachment::create([
            'name' => $validated['name'],
            'filename' => Str::of($validated['filename'])->replaceMatches('/[^\w.\- ]+/', '')->trim()->value() ?: 'attachment.pdf',
            'size' => $validated['size'],
            'chunks' => (int) ceil($validated['size'] / OutreachAttachment::ChunkBytes),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['id' => $attachment->id, 'chunks' => $attachment->chunks, 'chunkBytes' => OutreachAttachment::ChunkBytes]);
    }

    public function chunk(Request $request, OutreachAttachment $attachment, int $position): JsonResponse
    {
        abort_if($attachment->complete, 409);
        abort_unless($position >= 0 && $position < $attachment->chunks, 422);

        $bytes = $request->getContent();

        abort_if($bytes === '' || strlen($bytes) > OutreachAttachment::ChunkBytes, 422);

        $attachment->parts()->updateOrCreate(['position' => $position], ['data' => base64_encode($bytes)]);

        return response()->json(['ok' => true]);
    }

    public function complete(OutreachAttachment $attachment): JsonResponse
    {
        $contents = $attachment->contents();

        if ($attachment->parts()->count() !== $attachment->chunks || strlen($contents) !== $attachment->size || ! str_starts_with($contents, '%PDF')) {
            $attachment->delete();

            return response()->json(['message' => __('The upload did not arrive whole, or it is not a PDF. Please try again.')], 422);
        }

        $attachment->update([
            'complete' => true,
            'is_default' => OutreachAttachment::where('complete', true)->doesntExist(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function show(OutreachAttachment $attachment): Response
    {
        abort_unless($attachment->complete, 404);

        return response($attachment->contents(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$attachment->filename.'"',
        ]);
    }
}
