<?php

namespace App\Models;

/** Same sealed grant lifecycle, separately persisted exact document/version authority. */
final class DocumentFileOperationAssignment extends DocumentWorkspaceAssignment
{
    protected $table = 'document_file_operation_assignments';
}
