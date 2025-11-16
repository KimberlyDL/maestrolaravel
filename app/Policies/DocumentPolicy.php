<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Document;

class DocumentPolicy
{
    /* ==================== Review Context ==================== */

    public function createDocument(User $user, int $organizationId)
    {
        return $user->organizations()->where('organizations.id', $organizationId)->exists();
    }

    public function view(User $user, Document $doc)
    {
        // Public documents are viewable by anyone authenticated
        if ($doc->context === 'storage' && $doc->isPublic()) {
            return true;
        }

        // User can view if they're a member of the document's org
        $isMember = $user->organizations()->where('organizations.id', $doc->organization_id)->exists();

        if ($isMember) {
            return true;
        }

        // OR if they're a reviewer on any review request for this document
        if ($doc->context === 'review') {
            $isReviewer = $doc->reviewRequests()
                ->whereHas('recipients', function ($query) use ($user) {
                    $query->where('reviewer_user_id', $user->id);
                })
                ->exists();

            return $isReviewer;
        }

        return false;
    }

    public function addVersion(User $user, Document $doc)
    {
        // Only members can add versions (not reviewers)
        return $user->organizations()->where('organizations.id', $doc->organization_id)->exists();
    }

    public function submitForReview(User $user, Document $doc, int $publisherOrgId)
    {
        return $doc->organization_id === $publisherOrgId &&
            $user->organizations()->where('organizations.id', $publisherOrgId)->exists();
    }

    public function viewDocuments(User $user, int $organizationId)
    {
        return $user->organizations()->where('organizations.id', $organizationId)->exists();
    }

    /* ==================== Storage Context ==================== */

    /**
     * View storage documents in organization
     */
    public function viewStorage(User $user, int $organizationId): bool
    {
        return $user->organizations()->where('organizations.id', $organizationId)->exists();
    }

    /**
     * Upload to organization storage
     */
    public function uploadToStorage(User $user, int $organizationId): bool
    {
        // All org members can upload
        return $user->organizations()->where('organizations.id', $organizationId)->exists();
    }

    /**
     * Update storage document/folder
     */
    public function updateStorage(User $user, Document $document): bool
    {
        if ($document->context !== 'storage') {
            return false;
        }

        // Owner can edit
        if ($document->uploaded_by === $user->id || $document->created_by === $user->id) {
            return true;
        }

        // Org admins can edit
        $userRole = $document->organization->getUserRole($user->id);
        return in_array($userRole, ['admin', 'owner']);
    }

    /**
     * Delete storage document/folder
     */
    public function deleteStorage(User $user, Document $document): bool
    {
        if ($document->context !== 'storage') {
            return false;
        }

        // Owner can delete
        if ($document->uploaded_by === $user->id) {
            return true;
        }

        // Org admins can delete
        $userRole = $document->organization->getUserRole($user->id);
        return in_array($userRole, ['admin', 'owner']);
    }

    /**
     * Share document (org members/admins only)
     */
    public function share(User $user, Document $document): bool
    {
        // Must be storage context
        if ($document->context !== 'storage') {
            return false;
        }

        // Owner can share
        if ($document->uploaded_by === $user->id || $document->created_by === $user->id) {
            return true;
        }

        // Org members can share org documents
        $userRole = $document->organization->getUserRole($user->id);
        return in_array($userRole, ['admin', 'member', 'owner']);
    }
}
