<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentShare;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentShareController extends Controller
{
    /**
     * Get or create share configuration for a document
     */
    public function getShare(Document $document)
    {
        $this->authorize('view', $document);

        $share = $document->share;

        if (!$share) {
            // Default: org_only (organization members only)
            $share = DocumentShare::create([
                'document_id' => $document->id,
                'access_level' => 'org_only',
                'created_by' => auth()->id(),
                'share_token' => Str::random(32),
            ]);
        }

        return response()->json([
            'id' => $share->id,
            'access_level' => $share->access_level,
            'share_token' => $share->share_token,
            'share_url' => route('documents.public-access', ['token' => $share->share_token]),
            'is_valid' => $share->isValid(),
            'expires_at' => $share->expires_at,
        ]);
    }

    /**
     * Update share access level
     * access_level: 'org_only' (org members), 'public', 'link' (shareable link)
     */
    public function updateShare(Request $request, Document $document)
    {
        $this->authorize('share', $document);

        $data = $request->validate([
            'access_level' => 'required|in:org_only,public,link',
            'expires_at' => 'nullable|date_format:Y-m-d H:i:s',
        ]);

        $share = $document->share ?? DocumentShare::create([
            'document_id' => $document->id,
            'created_by' => auth()->id(),
            'share_token' => Str::random(32),
        ]);

        $share->update([
            'access_level' => $data['access_level'],
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Share settings updated',
            'share' => [
                'access_level' => $share->access_level,
                'share_url' => route('documents.public-access', ['token' => $share->share_token]),
                'expires_at' => $share->expires_at,
            ],
        ]);
    }

    /**
     * Revoke share link (set back to org_only)
     */
    public function revokeShare(Document $document)
    {
        $this->authorize('share', $document);

        if ($document->share) {
            $document->share->update(['access_level' => 'org_only']);
        }

        return response()->json(['message' => 'Share link revoked']);
    }

    /**
     * Public access: get document by share token (no auth required)
     */
    public function getPublicDocument($token)
    {
        $share = DocumentShare::where('share_token', $token)->first();

        if (!$share || !$share->isValid()) {
            return response()->json(['message' => 'Invalid or expired share link'], 404);
        }

        if ($share->access_level === 'org_only') {
            return response()->json(['message' => 'This document is not publicly shared'], 403);
        }

        $document = $share->document->load('latestVersion', 'organization');

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'type' => $document->type,
            'organization' => [
                'id' => $document->organization->id,
                'name' => $document->organization->name,
            ],
            'created_at' => $document->created_at,
            'latest_version' => [
                'id' => $document->latestVersion->id,
                'version_number' => $document->latestVersion->version_number,
                'file_path' => $document->latestVersion->file_path,
                'uploaded_at' => $document->latestVersion->created_at,
            ],
        ]);
    }
}
