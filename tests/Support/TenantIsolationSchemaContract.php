<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/** Test-only, reviewed baseline. See docs/architecture/tenant-isolation-schema-contract.md. */
final class TenantIsolationSchemaContract
{
    // The single executable classification source. Never infer scope from a name.
    public const TABLES = [
        'maturity_workflow_stages' => 'direct',
        'review_maturity_assignments' => 'direct',
        'maturity_workflow_configurations' => 'direct',
        'maturity_workflow_submissions' => 'direct',
        'maturity_workflow_evidence' => 'direct',
        'review_maturity_reviews' => 'direct',
        'maturity_workflow_assessments' => 'direct',
        'review_maturity_audit' => 'direct',
        'review_maturity_notifications' => 'direct',

        'approach_assignments' => 'direct',
        'approach_assignment_changes' => 'direct',
        'documents' => 'direct',
        'document_versions' => 'direct',
        'document_workspace_assignments' => 'direct',
        'document_workspace_assignment_changes' => 'direct',
        'document_metadata_revisions' => 'direct',
        'approaches' => 'direct',
        'approach_changes' => 'direct',
        'approach_content_revisions' => 'direct',
        'authorization_changes' => 'direct',
        'cache' => 'global',
        'cache_locks' => 'global',
        'failed_jobs' => 'global',
        'job_batches' => 'global',
        'jobs' => 'global',
        'institution_password_reset_tokens' => 'direct',
        'migrations' => 'global',
        'model_has_permissions' => 'direct',
        'model_has_roles' => 'direct',
        'organization_changes' => 'direct',
        'organization_memberships' => 'direct',
        'organizational_unit_changes' => 'direct',
        'organizational_units' => 'direct',
        'organizations' => 'root',
        'password_reset_tokens' => 'global',
        'permissions' => 'catalog',
        'role_has_permissions' => 'indirect',
        'roles' => 'direct',
        'sessions' => 'global',
        'users' => 'global',
    ];

    // Ordered local columns => [target table, ordered target columns]. All are
    // same-schema RESTRICT/RESTRICT. Global actors are NOT membership references.
    private const FOREIGN_KEYS = [
        'maturity_workflow_stages' => ['organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id' => ['organizations', 'id']],
        'review_maturity_assignments' => ['organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'grantee_id,organization_id' => ['users', 'id,organization_id'],
            'issuer_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id' => ['organizations', 'id'],
            'revoked_by,organization_id' => ['users', 'id,organization_id'],
            'organization_id,fixture_id,approach_id,stage_id' => ['maturity_workflow_stages', 'organization_id,fixture_id,approach_id,id']],
        'maturity_workflow_configurations' => ['actor_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id' => ['organizations', 'id'],
            'organization_id,fixture_id,approach_id,stage_id' => ['maturity_workflow_stages', 'organization_id,fixture_id,approach_id,id']],
        'maturity_workflow_submissions' => ['actor_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id,fixture_id,approach_id,stage_id,configuration_id' => ['maturity_workflow_configurations', 'organization_id,fixture_id,approach_id,stage_id,id'],
            'organization_id' => ['organizations', 'id']],
        'maturity_workflow_evidence' => ['organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id' => ['organizations', 'id'],
            'organization_id,fixture_id,approach_id,stage_id,submission_id' => ['maturity_workflow_submissions', 'organization_id,fixture_id,approach_id,stage_id,id'],
            'organization_id,document_id,document_version_id' => ['document_versions', 'organization_id,document_id,id']],
        'review_maturity_reviews' => ['organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id,fixture_id,approach_id,decision_assignment_id,reviewer_id,scope_key,decision_operation' => ['review_maturity_assignments', 'organization_id,fixture_id,approach_id,id,grantee_id,scope_key,operation'],
            'opened_by,organization_id' => ['users', 'id,organization_id'],
            'organization_id' => ['organizations', 'id'],
            'reviewer_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,approach_id,approach_revision_id' => ['approach_content_revisions', 'organization_id,approach_id,id'],
            'organization_id,fixture_id,approach_id,stage_id' => ['maturity_workflow_stages', 'organization_id,fixture_id,approach_id,id'],
            'organization_id,fixture_id,approach_id,stage_id,submission_id' => ['maturity_workflow_submissions', 'organization_id,fixture_id,approach_id,stage_id,id'],
            'organization_id,fixture_id,approach_id,scope_key,supersedes_id,previous_review_number' => ['review_maturity_reviews', 'organization_id,fixture_id,approach_id,scope_key,id,review_number']],
        'maturity_workflow_assessments' => ['organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id' => ['organizations', 'id'],
            'organization_id,fixture_id,approach_id,previous_assessment_id,previous_ordinal' => ['maturity_workflow_assessments', 'organization_id,fixture_id,approach_id,id,stage_ordinal'],
            'organization_id,fixture_id,approach_id,stage_id,review_id,submission_id,reviewer_id,approved_state' => ['review_maturity_reviews', 'organization_id,fixture_id,approach_id,stage_id,id,submission_id,reviewer_id,state'],
            'reviewer_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,fixture_id,approach_id,stage_id,stage_ordinal' => ['maturity_workflow_stages', 'organization_id,fixture_id,approach_id,id,stage_ordinal']],
        'review_maturity_audit' => ['actor_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id,fixture_id,approach_id,assignment_id,subject_id,scope_key,operation' => ['review_maturity_assignments', 'organization_id,fixture_id,approach_id,id,grantee_id,scope_key,operation'],
            'organization_id' => ['organizations', 'id'],
            'organization_id,fixture_id,approach_id,scope_key,review_id' => ['review_maturity_reviews', 'organization_id,fixture_id,approach_id,scope_key,id'],
            'organization_id,fixture_id,approach_id,stage_id' => ['maturity_workflow_stages', 'organization_id,fixture_id,approach_id,id'],
            'subject_id,organization_id' => ['users', 'id,organization_id']],
        'review_maturity_notifications' => ['organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'organization_id' => ['organizations', 'id'],
            'recipient_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,fixture_id,approach_id,scope_key,review_id' => ['review_maturity_reviews', 'organization_id,fixture_id,approach_id,scope_key,id']],

        'document_workspace_assignments' => [
            'organization_id' => ['organizations', 'id'],
            'grantee_id,organization_id' => ['users', 'id,organization_id'],
            'issuer_id,organization_id' => ['users', 'id,organization_id'],
            'revoked_by,organization_id' => ['users', 'id,organization_id'],
            'organization_id,document_id' => ['documents', 'organization_id,id'],
        ],
        'document_workspace_assignment_changes' => [
            'organization_id' => ['organizations', 'id'],
            'actor_id,organization_id' => ['users', 'id,organization_id'],
            'subject_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,document_id' => ['documents', 'organization_id,id'],
            'organization_id,fixture_id,assignment_id,subject_id,scope_key,operation' => ['document_workspace_assignments', 'organization_id,fixture_id,id,grantee_id,scope_key,operation'],
        ],
        'document_metadata_revisions' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,document_id' => ['documents', 'organization_id,id'],
            'actor_id,organization_id' => ['users', 'id,organization_id'],
        ],
        'approach_assignments' => [
            'organization_id' => ['organizations', 'id'],
            'grantee_id,organization_id' => ['users', 'id,organization_id'],
            'issuer_id,organization_id' => ['users', 'id,organization_id'],
            'initial_contributor_id,organization_id' => ['users', 'id,organization_id'],
            'organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'revoked_by,organization_id' => ['users', 'id,organization_id'],
        ],
        'approach_assignment_changes' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,fixture_id,assignment_id,subject_id' => ['approach_assignments', 'organization_id,fixture_id,id,grantee_id'],
            'organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'actor_id,organization_id' => ['users', 'id,organization_id'],
            'subject_id,organization_id' => ['users', 'id,organization_id'],
        ],
        'documents' => [
            'organization_id' => ['organizations', 'id'],
            'created_by' => ['users', 'id'],
        ],
        'document_versions' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,document_id' => ['documents', 'organization_id,id'],
            'actor_id' => ['users', 'id'],
        ],
        'approach_changes' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'actor_id' => ['users', 'id'],
            'organization_id,previous_owner_id' => ['organizational_units', 'organization_id,id'],
            'organization_id,new_owner_id' => ['organizational_units', 'organization_id,id'],
        ],
        'approaches' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,owning_unit_id' => ['organizational_units', 'organization_id,id'],
            'created_by' => ['users', 'id'],
        ],
        'approach_content_revisions' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,approach_id' => ['approaches', 'organization_id,id'],
            'actor_id' => ['users', 'id'],
        ],
        'users' => ['organization_id' => ['organizations', 'id']],
        'institution_password_reset_tokens' => ['account_id,organization_id' => ['users', 'id,organization_id']],
        'organization_memberships' => [
            'user_id' => ['users', 'id'], 'organization_id' => ['organizations', 'id'],
        ],
        'organizational_units' => [
            'organization_id' => ['organizations', 'id'],
            'organization_id,parent_id' => ['organizational_units', 'organization_id,id'],
        ],
        'roles' => ['organization_id' => ['organizations', 'id']],
        'role_has_permissions' => [
            'role_id' => ['roles', 'id'], 'permission_id' => ['permissions', 'id'],
        ],
        'model_has_roles' => [
            'organization_id,role_id' => ['roles', 'organization_id,id'],
            'model_id,organization_id' => ['organization_memberships', 'user_id,organization_id'],
        ],
        'model_has_permissions' => [
            'permission_id' => ['permissions', 'id'],
            'model_id,organization_id' => ['organization_memberships', 'user_id,organization_id'],
        ],
        'authorization_changes' => [
            'organization_id' => ['organizations', 'id'], 'actor_id' => ['users', 'id'],
            'subject_user_id' => ['users', 'id'],
        ],
        'organizational_unit_changes' => [
            'organization_id' => ['organizations', 'id'], 'actor_id' => ['users', 'id'],
            'organization_id,unit_id' => ['organizational_units', 'organization_id,id'],
            'organization_id,previous_parent_id' => ['organizational_units', 'organization_id,id'],
            'organization_id,new_parent_id' => ['organizational_units', 'organization_id,id'],
        ],
        'organization_changes' => [
            'organization_id' => ['organizations', 'id'], 'actor_id' => ['users', 'id'],
        ],
    ];

    private const UNIQUE_KEYS = [
        'maturity_workflow_stages' => ['organization_id,fixture_id,approach_id,stage', 'organization_id,fixture_id,approach_id,id,stage_ordinal', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id', 'id'],
        'review_maturity_assignments' => ['id', 'organization_id,fixture_id,approach_id,id,grantee_id,scope_key,operation', 'organization_id,fixture_id,approach_id,grantee_id,operation,scope_key', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id'],
        'maturity_workflow_configurations' => ['organization_id,fixture_id,approach_id,stage_id,id', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id', 'organization_id,fixture_id,approach_id,stage_id,version_number', 'id'],
        'maturity_workflow_submissions' => ['organization_id,fixture_id,approach_id,stage_id,id', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id', 'organization_id,fixture_id,approach_id,stage_id,version_number', 'id'],
        'maturity_workflow_evidence' => ['organization_id,fixture_id,approach_id,submission_id,question_public_id,expected_evidence_public_id,document_version_id', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id', 'id'],
        'review_maturity_reviews' => ['id', 'organization_id,fixture_id,approach_id,target_kind,scope_key,active_slot', 'organization_id,fixture_id,approach_id,stage_id,id,submission_id,reviewer_id,state', 'organization_id,fixture_id,approach_id,decision_key', 'organization_id,fixture_id,approach_id,target_kind,scope_key,review_number', 'organization_id,fixture_id,approach_id,scope_key,id,review_number', 'public_id', 'organization_id,fixture_id,approach_id,request_key', 'organization_id,fixture_id,approach_id,id', 'organization_id,fixture_id,approach_id,scope_key,id', 'organization_id,id'],
        'maturity_workflow_assessments' => ['organization_id,fixture_id,approach_id,stage_id', 'organization_id,fixture_id,approach_id,id,stage_ordinal', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id', 'id'],
        'review_maturity_audit' => ['id', 'organization_id,fixture_id,event_key', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id'],
        'review_maturity_notifications' => ['id', 'organization_id,fixture_id,recipient_id,event_key', 'public_id', 'organization_id,fixture_id,approach_id,id', 'organization_id,id'],

        'document_workspace_assignments' => ['id', 'public_id', 'organization_id,id', 'organization_id,fixture_id,grantee_id,operation,scope_key',
            'organization_id,fixture_id,id,grantee_id,scope_key,operation'],
        'document_workspace_assignment_changes' => ['id', 'public_id', 'organization_id,id', 'organization_id,fixture_id,event_key'],
        'document_metadata_revisions' => ['id', 'public_id', 'organization_id,id', 'organization_id,document_id,version_number'],
        'approach_assignments' => ['id', 'public_id', 'organization_id,id', 'organization_id,fixture_id,id,grantee_id',
            'organization_id,fixture_id,grantee_id,operation,scope_key'],
        'approach_assignment_changes' => ['id', 'public_id', 'organization_id,fixture_id,event_key'],
        'documents' => ['id', 'public_id', 'organization_id,id'],
        'document_versions' => ['id', 'public_id', 'document_id,version_number', 'storage_key', 'organization_id,document_id,id'],
        'approach_changes' => ['id', 'public_id'],
        'approaches' => ['id', 'public_id', 'organization_id,id'],
        'approach_content_revisions' => ['id', 'public_id', 'approach_id,version_number', 'organization_id,approach_id,id'],
        'users' => ['id', 'public_id', 'id,organization_id', 'identity_namespace,email'],
        'institution_password_reset_tokens' => ['account_id'],
        'organizations' => ['id', 'public_id'],
        'organization_memberships' => ['id', 'user_id,organization_id'],
        'organizational_units' => ['id', 'public_id', 'organization_id,id'],
        'permissions' => ['id', 'name,guard_name'],
        'roles' => ['id', 'organization_id,id', 'organization_id,name,guard_name'],
        'role_has_permissions' => ['permission_id,role_id'],
        'model_has_roles' => ['organization_id,role_id,model_id,model_type'],
        'model_has_permissions' => ['organization_id,permission_id,model_id,model_type'],
        'authorization_changes' => ['id'],
        'organizational_unit_changes' => ['id'],
        'organization_changes' => ['id'],
    ];

    private const NULLABLE_REFERENCES = [
        'maturity_workflow_stages' => [],
        'review_maturity_assignments' => ['stage_id', 'revoked_at', 'revoked_by', 'scope_key'],
        'maturity_workflow_configurations' => [],
        'maturity_workflow_submissions' => [],
        'maturity_workflow_evidence' => [],
        'review_maturity_reviews' => ['stage_id', 'approach_revision_id', 'submission_id', 'reviewer_id', 'decision_assignment_id', 'decision_key', 'decision_fingerprint', 'decided_at', 'reason', 'supersedes_id', 'previous_review_number', 'scope_key', 'active_slot', 'decision_operation'],
        'maturity_workflow_assessments' => ['previous_assessment_id', 'previous_ordinal', 'approved_state', 'notes'],
        'review_maturity_audit' => ['subject_id', 'stage_id', 'assignment_id', 'review_id', 'operation', 'scope_key'],
        'review_maturity_notifications' => ['stage_id', 'read_at', 'scope_key'],

        'document_workspace_assignments' => ['document_id', 'revoked_by'],
        // Stored COALESCE scope_key is reported nullable by MySQL; its exact
        // generation expression is checked separately and can never yield NULL.
        'document_workspace_assignment_changes' => ['document_id', 'assignment_id', 'scope_key'],
        'approach_assignments' => ['approach_id', 'initial_contributor_id', 'revoked_by'],
        'approach_assignment_changes' => ['approach_id', 'assignment_id'],
        'approach_changes' => ['previous_owner_id', 'new_owner_id'],
        'organizational_units' => ['parent_id'],
        'authorization_changes' => ['subject_user_id'],
        'organizational_unit_changes' => ['previous_parent_id', 'new_parent_id'],
    ];

    private const CHECKS = [
        'approach_assignment_changes' => ['approach_assignment_changes_event_check', "(eventin('granted','revoked','expiration_observed','access_denied'))"],
        'approach_changes' => ['approach_changes_event_check', "(eventin('metadata_updated','owner_changed'))"],
        'organizational_unit_changes' => ['unit_changes_event_check', "(eventin('created','renamed','reparented'))"],
        'organization_changes' => ['organization_changes_event_check', "(eventin('created','renamed'))"],
    ];

    private const ASSIGNMENT_CHECKS = [
        'approach_assignments' => ['approach_assignments_test_check', 'approach_assignments_scope_check',
            'approach_assignments_recipient_check', 'approach_assignments_time_check', 'approach_assignments_revocation_check'],
        'approach_assignment_changes' => ['approach_assignment_changes_test_check', 'approach_assignment_changes_reference_check',
            'approach_assignment_changes_operation_check'],
    ];

    private const DOCUMENT_WORKSPACE_CHECKS = [
        'document_workspace_assignments' => [
            'doc_ws_assignment_class' => "((`classification`='TEST_ONLY')and(`scenario`='DOCUMENT_LOCAL_001'))",
            'doc_ws_assignment_fixture' => "((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,'[^0-9a-f]'))))",
            'doc_ws_assignment_scope' => "(((`scope_kind`='institution')and(`document_id`isnull)and(`operation`in('create','list')))or((`scope_kind`='document')and(`document_id`isnotnull)and(`operation`in('view','metadata','append','history'))))",
            'doc_ws_assignment_period' => '(((`starts_at`+interval24hour)isnotnull)and(`expires_at`>`starts_at`)and(`expires_at`=(`starts_at`+interval24hour)))',
            'doc_ws_assignment_revoke' => '(((`revoked_at`isnull)and(`revoked_by`isnull))or((`revoked_at`isnotnull)and(`revoked_by`isnotnull)and(`revoked_at`>=`starts_at`)))',
        ],
        'document_workspace_assignment_changes' => [
            'doc_ws_change_class' => "((`classification`='TEST_ONLY')and(`scenario`='DOCUMENT_LOCAL_001'))",
            'doc_ws_change_fixture' => "((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,'[^0-9a-f]'))))",
            'doc_ws_change_kind' => "(eventin('granted','revoked','expiration_observed','access_denied'))",
            'doc_ws_change_reference' => "((`assignment_id`isnotnull)or(event='access_denied'))",
            'doc_ws_change_scope' => "(((`document_id`isnull)and(`operation`in('create','list')))or((`document_id`isnotnull)and(`operation`in('view','metadata','append','history'))))",
        ],
        'document_metadata_revisions' => [
            'doc_meta_number' => '((`version_number`>0)and(`version_number`<=4294967295))',
            'doc_meta_title' => '(char_length(trim(`title`))>0)',
            'doc_meta_description' => '(char_length(trim(`description`))>0)',
        ],
    ];

    // Explicit reviewed MySQL 8.4.13 workflow shape. Never inferred from live metadata by the gate.
    private const WORKFLOW = ['maturity_workflow_stages' => ['columns' => ['id' => ['type' => 'bigint unsigned',
        'nullable' => false],
        'public_id' => ['type' => 'char(26)',
            'nullable' => false],
        'organization_id' => ['type' => 'bigint unsigned',
            'nullable' => false],
        'fixture_id' => ['type' => 'char(32)',
            'nullable' => false],
        'approach_id' => ['type' => 'bigint unsigned',
            'nullable' => false],
        'scenario' => ['type' => 'varchar(40)',
            'nullable' => false],
        'classification' => ['type' => 'varchar(16)',
            'nullable' => false],
        'created_at' => ['type' => 'datetime',
            'nullable' => false],
        'stage' => ['type' => 'varchar(20)',
            'nullable' => false],
        'stage_ordinal' => ['type' => 'tinyint unsigned',
            'nullable' => false]],
        'checks' => ['mw_stage_class' => ['enforced' => true,
            'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
            'mw_stage_fixture' => ['enforced' => true,
                'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
            'mw_stage_kind' => ['enforced' => true,
                'clause' => '(`stage`in(\'orientation\',\'system\',\'implementation\',\'measurement\',\'improvement\'))'],
            'mw_stage_order' => ['enforced' => true,
                'clause' => '(`stage_ordinal`=(case`stage`when\'orientation\'then1when\'system\'then2when\'implementation\'then3when\'measurement\'then4when\'improvement\'then5end))']],
        'generated' => []],
        'review_maturity_assignments' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'grantee_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'issuer_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'operation' => ['type' => 'varchar(24)',
                'nullable' => false],
            'starts_at' => ['type' => 'datetime',
                'nullable' => false],
            'expires_at' => ['type' => 'datetime',
                'nullable' => false],
            'revoked_at' => ['type' => 'datetime',
                'nullable' => true],
            'revoked_by' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'scope_key' => ['type' => 'bigint unsigned',
                'nullable' => true]],
            'checks' => ['rm_grant_class' => ['enforced' => true,
                'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'rm_grant_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
                'rm_grant_operation' => ['enforced' => true,
                    'clause' => '(((`stage_id`isnull)and(`operation`in(\'read_workflow\',\'edit_content\',\'review_content\')))or((`stage_id`isnotnull)and(`operation`in(\'configure_stage\',\'answer_stage\',\'review_stage\'))))'],
                'rm_grant_period' => ['enforced' => true,
                    'clause' => '(((`starts_at`+interval24hour)isnotnull)and(`expires_at`>`starts_at`)and(`expires_at`=(`starts_at`+interval24hour)))'],
                'rm_grant_revoked' => ['enforced' => true,
                    'clause' => '(((`revoked_at`isnull)and(`revoked_by`isnull))or((`revoked_at`isnotnull)and(`revoked_by`isnotnull)and(`revoked_at`>=`starts_at`)))']],
            'generated' => ['scope_key' => ['expression' => 'coalesce(`stage_id`,0)',
                'extra' => 'STORED GENERATED']]],
        'maturity_workflow_configurations' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'version_number' => ['type' => 'int unsigned',
                'nullable' => false],
            'actor_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'configuration_json' => ['type' => 'json',
                'nullable' => false],
            'fingerprint' => ['type' => 'char(64)',
                'nullable' => false]],
            'checks' => ['mw_config_class' => ['enforced' => true,
                'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'mw_config_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
                'mw_config_hash' => ['enforced' => true,
                    'clause' => 'regexp_like(`fingerprint`,\'^[0-9a-f]{64}$\',\'c\')'],
                'mw_config_json' => ['enforced' => true,
                    'clause' => '((json_type(`configuration_json`)=\'OBJECT\')andjson_contains_path(`configuration_json`,\'all\',\'$.questions\',\'$.scale\')and(json_type(json_extract(`configuration_json`,\'$.questions\'))=\'ARRAY\')and(json_type(json_extract(`configuration_json`,\'$.scale\'))=\'ARRAY\')and(json_length(json_extract(`configuration_json`,\'$.scale\'))>0))'],
                'mw_config_number' => ['enforced' => true,
                    'clause' => '((`version_number`>0)and(`version_number`<=4294967295))']],
            'generated' => []],
        'maturity_workflow_submissions' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'configuration_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'version_number' => ['type' => 'int unsigned',
                'nullable' => false],
            'actor_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'content_json' => ['type' => 'json',
                'nullable' => false],
            'fingerprint' => ['type' => 'char(64)',
                'nullable' => false]],
            'checks' => ['mw_sub_class' => ['enforced' => true,
                'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'mw_sub_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
                'mw_sub_hash' => ['enforced' => true,
                    'clause' => 'regexp_like(`fingerprint`,\'^[0-9a-f]{64}$\',\'c\')'],
                'mw_sub_json' => ['enforced' => true,
                    'clause' => '((json_type(`content_json`)=\'OBJECT\')andjson_contains_path(`content_json`,\'all\',\'$.answers\',\'$.evidence\',\'$.configuration\')and(json_type(json_extract(`content_json`,\'$.answers\'))=\'ARRAY\')and(json_type(json_extract(`content_json`,\'$.evidence\'))=\'ARRAY\')and(json_type(json_extract(`content_json`,\'$.configuration\'))=\'OBJECT\'))'],
                'mw_sub_number' => ['enforced' => true,
                    'clause' => '((`version_number`>0)and(`version_number`<=4294967295))']],
            'generated' => []],
        'maturity_workflow_evidence' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'submission_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'question_public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'expected_evidence_public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'document_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'document_version_id' => ['type' => 'bigint unsigned',
                'nullable' => false]],
            'checks' => ['mw_evidence_class' => ['enforced' => true,
                'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'mw_evidence_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))']],
            'generated' => []],
        'review_maturity_reviews' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'target_kind' => ['type' => 'varchar(24)',
                'nullable' => false],
            'approach_revision_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'submission_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'review_number' => ['type' => 'int unsigned',
                'nullable' => false],
            'fingerprint' => ['type' => 'char(64)',
                'nullable' => false],
            'opened_by' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'request_key' => ['type' => 'char(26)',
                'nullable' => false],
            'state' => ['type' => 'varchar(20)',
                'nullable' => false],
            'reviewer_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'decision_assignment_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'decision_key' => ['type' => 'char(26)',
                'nullable' => true],
            'decision_fingerprint' => ['type' => 'char(64)',
                'nullable' => true],
            'decided_at' => ['type' => 'datetime',
                'nullable' => true],
            'reason' => ['type' => 'longtext',
                'nullable' => true],
            'supersedes_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'previous_review_number' => ['type' => 'int unsigned',
                'nullable' => true],
            'scope_key' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'active_slot' => ['type' => 'tinyint unsigned',
                'nullable' => true],
            'decision_operation' => ['type' => 'varchar(24)',
                'nullable' => true]],
            'checks' => ['rm_review_chain' => ['enforced' => true,
                'clause' => '(((`review_number`=1)and(`supersedes_id`isnull))or((`review_number`>1)and(`supersedes_id`isnotnull)))'],
                'rm_review_class' => ['enforced' => true,
                    'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'rm_review_decision_hash' => ['enforced' => true,
                    'clause' => '((`decision_fingerprint`isnull)orregexp_like(`decision_fingerprint`,\'^[0-9a-f]{64}$\',\'c\'))'],
                'rm_review_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
                'rm_review_hash' => ['enforced' => true,
                    'clause' => 'regexp_like(`fingerprint`,\'^[0-9a-f]{64}$\',\'c\')'],
                'rm_review_target' => ['enforced' => true,
                    'clause' => '(((`target_kind`=\'approach_content\')and(`stage_id`isnull)and(`approach_revision_id`isnotnull)and(`submission_id`isnull))or((`target_kind`=\'maturity_submission\')and(`stage_id`isnotnull)and(`approach_revision_id`isnull)and(`submission_id`isnotnull)))'],
                'rm_review_time' => ['enforced' => true,
                    'clause' => '((`decided_at`isnull)or(`decided_at`>=`created_at`))'],
                'rm_review_transition' => ['enforced' => true,
                    'clause' => '(((`state`=\'pending\')and(`reviewer_id`isnull)and(`decided_at`isnull)and(`decision_assignment_id`isnull)and(`decision_key`isnull)and(`decision_fingerprint`isnull)and(`reason`isnull))or((`state`=\'superseded\')and(`reviewer_id`isnull)and(`decided_at`isnotnull)and(`decision_assignment_id`isnull)and(`decision_key`isnull)and(`decision_fingerprint`isnull)and(`reason`isnull))or((`state`in(\'approved\',\'changes_requested\'))and(`reviewer_id`isnotnull)and(`decided_at`isnotnull)and(`decision_assignment_id`isnotnull)and(`decision_key`isnotnull)and(`decision_fingerprint`isnotnull)and((`state`=\'approved\')or((`reason`isnotnull)and(char_length(trim(`reason`))>0)))))'],
                'rm_review_version' => ['enforced' => true,
                    'clause' => '((`review_number`>0)and(`review_number`<=4294967295))']],
            'generated' => ['previous_review_number' => ['expression' => '(case when (`review_number` > 1) then (`review_number` - 1) else NULL end)',
                'extra' => 'STORED GENERATED'],
                'scope_key' => ['expression' => 'coalesce(`stage_id`,0)',
                    'extra' => 'STORED GENERATED'],
                'active_slot' => ['expression' => '(case when (`state` = _utf8mb4\\\'pending\\\') then 1 else NULL end)',
                    'extra' => 'STORED GENERATED'],
                'decision_operation' => ['expression' => '(case when (`target_kind` = _utf8mb4\\\'approach_content\\\') then _utf8mb4\\\'review_content\\\' else _utf8mb4\\\'review_stage\\\' end)',
                    'extra' => 'STORED GENERATED']]],
        'maturity_workflow_assessments' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'stage_ordinal' => ['type' => 'tinyint unsigned',
                'nullable' => false],
            'submission_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'review_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'reviewer_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'previous_assessment_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'previous_ordinal' => ['type' => 'tinyint unsigned',
                'nullable' => true],
            'approved_state' => ['type' => 'varchar(20)',
                'nullable' => true],
            'selected_band_index' => ['type' => 'int unsigned',
                'nullable' => false],
            'snapshot_json' => ['type' => 'json',
                'nullable' => false],
            'reason' => ['type' => 'longtext',
                'nullable' => false],
            'notes' => ['type' => 'longtext',
                'nullable' => true]],
            'checks' => ['mw_assess_class' => ['enforced' => true,
                'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'mw_assess_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
                'mw_assess_json' => ['enforced' => true,
                    'clause' => '((json_type(`snapshot_json`)=\'OBJECT\')andjson_contains_path(`snapshot_json`,\'all\',\'$.scale\',\'$.selected_band_index\',\'$.answers\')and(json_type(json_extract(`snapshot_json`,\'$.scale\'))=\'ARRAY\')and(json_type(json_extract(`snapshot_json`,\'$.selected_band_index\'))=\'INTEGER\')and(`selected_band_index`<json_length(json_extract(`snapshot_json`,\'$.scale\')))and(json_extract(`snapshot_json`,\'$.selected_band_index\')=`selected_band_index`))'],
                'mw_assess_reason' => ['enforced' => true,
                    'clause' => '(char_length(trim(`reason`))>0)'],
                'mw_assess_sequence' => ['enforced' => true,
                    'clause' => '(((`stage_ordinal`=1)and(`previous_assessment_id`isnull))or((`stage_ordinal`between2and5)and(`previous_assessment_id`isnotnull)))']],
            'generated' => ['previous_ordinal' => ['expression' => '(case when (`stage_ordinal` > 1) then (`stage_ordinal` - 1) else NULL end)',
                'extra' => 'STORED GENERATED'],
                'approved_state' => ['expression' => '_utf8mb4\\\'approved\\\'',
                    'extra' => 'STORED GENERATED']]],
        'review_maturity_audit' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'actor_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'subject_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'assignment_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'review_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'operation' => ['type' => 'varchar(24)',
                'nullable' => true],
            'event' => ['type' => 'varchar(28)',
                'nullable' => false],
            'event_key' => ['type' => 'varchar(100)',
                'nullable' => false],
            'scope_key' => ['type' => 'bigint unsigned',
                'nullable' => true]],
            'checks' => ['rm_audit_class' => ['enforced' => true,
                'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'rm_audit_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
                'rm_audit_operation_scope' => ['enforced' => true,
                    'clause' => '((`operation`isnull)or((`stage_id`isnull)and(`operation`in(\'read_workflow\',\'edit_content\',\'review_content\')))or((`stage_id`isnotnull)and(`operation`in(\'configure_stage\',\'answer_stage\',\'review_stage\'))))'],
                'rm_audit_reference' => ['enforced' => true,
                    'clause' => '(((eventin(\'granted\',\'revoked\',\'expiration_observed\',\'access_denied\'))and(`subject_id`isnotnull)and(`review_id`isnull)and(`operation`isnotnull)and(`operation`in(\'read_workflow\',\'edit_content\',\'review_content\',\'configure_stage\',\'answer_stage\',\'review_stage\'))and((`assignment_id`isnotnull)or(event=\'access_denied\')))or((eventin(\'review_opened\',\'changes_requested\',\'review_approved\',\'review_superseded\',\'assessment_recorded\'))and(`review_id`isnotnull)and(`assignment_id`isnull)and(`subject_id`isnull)and(`operation`isnull)))']],
            'generated' => ['scope_key' => ['expression' => 'coalesce(`stage_id`,0)',
                'extra' => 'STORED GENERATED']]],
        'review_maturity_notifications' => ['columns' => ['id' => ['type' => 'bigint unsigned',
            'nullable' => false],
            'public_id' => ['type' => 'char(26)',
                'nullable' => false],
            'organization_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'fixture_id' => ['type' => 'char(32)',
                'nullable' => false],
            'approach_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'scenario' => ['type' => 'varchar(40)',
                'nullable' => false],
            'classification' => ['type' => 'varchar(16)',
                'nullable' => false],
            'created_at' => ['type' => 'datetime',
                'nullable' => false],
            'recipient_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'stage_id' => ['type' => 'bigint unsigned',
                'nullable' => true],
            'review_id' => ['type' => 'bigint unsigned',
                'nullable' => false],
            'event' => ['type' => 'varchar(24)',
                'nullable' => false],
            'event_key' => ['type' => 'char(64)',
                'nullable' => false],
            'title' => ['type' => 'varchar(255)',
                'nullable' => false],
            'reason' => ['type' => 'varchar(255)',
                'nullable' => false],
            'read_at' => ['type' => 'datetime',
                'nullable' => true],
            'scope_key' => ['type' => 'bigint unsigned',
                'nullable' => true]],
            'checks' => ['rm_notice_class' => ['enforced' => true,
                'clause' => '((`classification`=\'TEST_ONLY\')and(`scenario`=\'REVIEW_MATURITY_LOCAL_001\'))'],
                'rm_notice_event' => ['enforced' => true,
                    'clause' => '(eventin(\'review_opened\',\'changes_requested\',\'approved\'))'],
                'rm_notice_fixture' => ['enforced' => true,
                    'clause' => '((char_length(`fixture_id`)=32)and(not(regexp_like(`fixture_id`,\'[^0-9a-f]\'))))'],
                'rm_notice_key' => ['enforced' => true,
                    'clause' => 'regexp_like(`event_key`,\'^[0-9a-f]{64}$\',\'c\')'],
                'rm_notice_read' => ['enforced' => true,
                    'clause' => '((`read_at`isnull)or(`read_at`>=`created_at`))'],
                'rm_notice_text' => ['enforced' => true,
                    'clause' => '((char_length(trim(`title`))>0)and(char_length(trim(`reason`))>0))']],
            'generated' => ['scope_key' => ['expression' => 'coalesce(`stage_id`,0)',
                'extra' => 'STORED GENERATED']]]];

    /** Read only metadata, never tenant rows. Call after MySqlTestCase's guards. */
    public static function read(Connection $db): array
    {
        if ($db->getDriverName() !== 'mysql') {
            throw new RuntimeException('The schema contract requires actual MySQL metadata.');
        }
        $schema = [];
        foreach ($db->select("SELECT TABLE_NAME AS name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $table) {
            $schema[$table->name] = ['engine' => $table->engine, 'columns' => [], 'foreign_keys' => [], 'indexes' => [], 'checks' => []];
        }
        foreach ($db->select('SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, GENERATION_EXPRESSION AS generation, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $column) {
            if (isset($schema[$column->tbl])) {
                $schema[$column->tbl]['columns'][$column->col] = ['type' => $column->type, 'nullable' => $column->nullable === 'YES'];
                if (isset(self::WORKFLOW[$column->tbl]) && $column->generation !== '') {
                    $schema[$column->tbl]['generated'][$column->col] = ['expression' => $column->generation, 'extra' => $column->extra];
                }
                if ($column->col === 'scope_key' && in_array($column->tbl, ['document_workspace_assignments', 'document_workspace_assignment_changes'], true)) {
                    $schema[$column->tbl]['scope_generation'] = ['expression' => $column->generation, 'extra' => $column->extra];
                }
            }
        }
        $foreignKeys = $db->select(<<<'SQL'
SELECT k.TABLE_NAME AS tbl, k.CONSTRAINT_NAME AS name,
GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS column_list,
k.REFERENCED_TABLE_SCHEMA AS target_schema, k.REFERENCED_TABLE_NAME AS target,
GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS reference_list,
r.DELETE_RULE AS delete_rule, r.UPDATE_RULE AS update_rule
FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.TABLE_NAME = r.TABLE_NAME AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, r.DELETE_RULE, r.UPDATE_RULE
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME
SQL);
        foreach ($foreignKeys as $key) {
            $schema[$key->tbl]['foreign_keys'][$key->name] = [
                'columns' => $key->column_list, 'target' => $key->target, 'references' => $key->reference_list,
                'local' => $key->target_schema === $db->getDatabaseName(), 'delete' => $key->delete_rule, 'update' => $key->update_rule,
            ];
        }
        $indexes = $db->select(<<<'SQL'
SELECT TABLE_NAME AS tbl, INDEX_NAME AS name, NON_UNIQUE AS non_unique,
GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS column_list,
SUM(SUB_PART IS NOT NULL OR COLUMN_NAME IS NULL) AS partial_columns
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME
SQL);
        foreach ($indexes as $index) {
            $schema[$index->tbl]['indexes'][$index->name] = [
                'columns' => $index->column_list, 'unique' => (int) $index->non_unique === 0, 'full' => (int) $index->partial_columns === 0,
            ];
        }
        $checks = $db->select(<<<'SQL'
SELECT t.TABLE_NAME AS tbl, t.CONSTRAINT_NAME AS name, t.ENFORCED AS enforced, c.CHECK_CLAUSE AS clause
FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c
ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME
WHERE t.TABLE_SCHEMA = DATABASE() AND t.CONSTRAINT_TYPE = 'CHECK'
ORDER BY t.TABLE_NAME, t.CONSTRAINT_NAME
SQL);
        foreach ($checks as $check) {
            // Only the bounded literal event expressions above, not a SQL parser.
            // Preserve literal contents: whitespace/charset text inside a value
            // must never make a different event appear to be an approved one.
            $clause = preg_replace_callback("/'[^']*'|`event`|_utf8mb4|\\s+/", static fn (array $match): string => match (true) {
                str_starts_with($match[0], "'") => $match[0],
                $match[0] === '`event`' => 'event',
                default => '',
            }, str_replace("\\'", "'", $check->clause));
            $schema[$check->tbl]['checks'][$check->name] = ['enforced' => $check->enforced === 'YES', 'clause' => $clause];
        }

        return $schema;
    }

    /** @return list<string> Schema names only; no row values or SQL credentials. */
    public static function violations(array $schema): array
    {
        $errors = [];
        foreach (array_diff(array_keys($schema), array_keys(self::TABLES)) as $table) {
            $errors[] = "$table: unclassified base table; add a reviewed tenant-scope classification.";
        }
        foreach (self::TABLES as $table => $scope) {
            if (! isset($schema[$table])) {
                $errors[] = "$table: reviewed base table is missing or renamed.";

                continue;
            }
            $actual = $schema[$table];
            if ($table === 'users') {
                foreach (['organization_id' => ['bigint unsigned', true], 'public_id' => ['char(26)', true],
                    'principal_type' => ['varchar(16)', false], 'identity_namespace' => ['varchar(48)', true]] as $column => [$type, $nullable]) {
                    if (($actual['columns'][$column] ?? null) !== ['type' => $type, 'nullable' => $nullable]) {
                        $errors[] = "users: $column must retain its reviewed identity type and nullability.";
                    }
                }
            }
            if ($actual['engine'] !== 'InnoDB') {
                $errors[] = "$table: expected InnoDB for the reviewed integrity contract.";
            }
            if ($scope === 'direct' && ($actual['columns']['organization_id'] ?? null) !== ['type' => 'bigint unsigned', 'nullable' => false]) {
                $errors[] = "$table: organization_id must be non-null BIGINT UNSIGNED.";
            }
            if (in_array($scope, ['direct', 'indirect'], true) && ! isset(self::FOREIGN_KEYS[$table])) {
                $errors[] = "$table: tenant classification requires explicit reviewed foreign-key relationships.";
            }
            foreach (self::FOREIGN_KEYS[$table] ?? [] as $columns => [$target, $references]) {
                foreach (explode(',', $columns) as $column) {
                    if ($column === 'organization_id') {
                        continue; // Already checked for every directly scoped table.
                    }
                    $nullable = in_array($column, self::NULLABLE_REFERENCES[$table] ?? [], true);
                    $type = self::WORKFLOW[$table]['columns'][$column]['type'] ?? match (true) {
                        in_array($table, ['approach_assignment_changes', 'document_workspace_assignment_changes'], true) && $column === 'fixture_id' => 'char(32)',
                        $table === 'document_workspace_assignment_changes' && $column === 'operation' => 'varchar(20)',
                        default => 'bigint unsigned',
                    };
                    if (($actual['columns'][$column] ?? null) !== ['type' => $type, 'nullable' => $nullable]) {
                        $errors[] = "$table: $column must retain its reviewed ".strtoupper($type).' type and nullability.';
                    }
                }
                $required = ['columns' => $columns, 'target' => $target, 'references' => $references,
                    'local' => true, 'delete' => 'RESTRICT', 'update' => 'RESTRICT'];
                if (! in_array($required, $actual['foreign_keys'], true)) {
                    $errors[] = "$table: required restrictive FK ($columns) -> $target($references) in the same schema is missing or changed.";
                }
            }
            foreach (self::UNIQUE_KEYS[$table] ?? [] as $columns) {
                if (! in_array(['columns' => $columns, 'unique' => true, 'full' => true], $actual['indexes'], true)) {
                    $errors[] = "$table: required full-column UNIQUE ($columns) is missing or changed.";
                }
            }
            if (isset(self::CHECKS[$table])) {
                [$name, $clause] = self::CHECKS[$table];
                $eventType = $table === 'approach_assignment_changes' ? 'varchar(24)' : 'varchar(20)';
                if (($actual['columns']['event'] ?? null) !== ['type' => $eventType, 'nullable' => false]) {
                    $errors[] = "$table: event must remain non-null ".strtoupper($eventType).'.';
                }
                if (($actual['checks'][$name] ?? null) !== ['enforced' => true, 'clause' => $clause]) {
                    $errors[] = "$table: required enforced CHECK $name is missing or changed.";
                }
            }
            if (isset(self::ASSIGNMENT_CHECKS[$table])) {
                foreach (self::ASSIGNMENT_CHECKS[$table] as $name) {
                    if (($actual['checks'][$name]['enforced'] ?? false) !== true) {
                        $errors[] = "$table: required enforced assignment CHECK $name is missing or disabled.";
                    }
                }
                $columns = ['id' => 'bigint unsigned', 'public_id' => 'char(26)', 'fixture_id' => 'char(32)',
                    'classification' => 'varchar(12)', 'scenario' => 'varchar(32)', 'operation' => 'varchar(32)', 'created_at' => 'datetime'];
                if ($table === 'approach_assignments') {
                    $columns += ['scope_kind' => 'varchar(16)', 'starts_at' => 'datetime', 'expires_at' => 'datetime'];
                }
                foreach ($columns as $column => $type) {
                    if (($actual['columns'][$column] ?? null) !== ['type' => $type, 'nullable' => false]) {
                        $errors[] = "$table: $column must retain its reviewed assignment type and nullability.";
                    }
                }
            }
            if (isset(self::DOCUMENT_WORKSPACE_CHECKS[$table])) {
                foreach (self::DOCUMENT_WORKSPACE_CHECKS[$table] as $name => $clause) {
                    if (($actual['checks'][$name] ?? null) !== ['enforced' => true, 'clause' => $clause]) {
                        $errors[] = "$table: required enforced document CHECK $name is missing or changed.";
                    }
                }
                $columns = ['id' => ['bigint unsigned', false], 'public_id' => ['char(26)', false], 'created_at' => ['datetime', false]];
                if ($table === 'document_metadata_revisions') {
                    $columns += ['title' => ['varchar(255)', false], 'description' => ['longtext', false], 'version_number' => ['int unsigned', false]];
                } else {
                    $columns += ['fixture_id' => ['char(32)', false], 'scenario' => ['varchar(40)', false], 'classification' => ['varchar(16)', false],
                        'operation' => ['varchar(20)', false], 'scope_key' => ['bigint unsigned', true]];
                    $columns += $table === 'document_workspace_assignments' ? ['scope_kind' => ['varchar(16)', false],
                        'starts_at' => ['datetime', false], 'expires_at' => ['datetime', false], 'revoked_at' => ['datetime', true]]
                        : ['event' => ['varchar(24)', false], 'event_key' => ['varchar(100)', false]];
                    if (($actual['scope_generation'] ?? null) !== ['expression' => 'coalesce(`document_id`,0)', 'extra' => 'STORED GENERATED']) {
                        $errors[] = "$table: scope_key must retain the stored non-NULL COALESCE document scope expression.";
                    }
                }
                foreach ($columns as $column => [$type, $nullable]) {
                    if (($actual['columns'][$column] ?? null) !== ['type' => $type, 'nullable' => $nullable]) {
                        $errors[] = "$table: $column must retain its reviewed document type and nullability.";
                    }
                }
            }
            if (isset(self::WORKFLOW[$table])) {
                $expected = self::WORKFLOW[$table];
                if ($actual['columns'] !== $expected['columns']) {
                    $errors[] = "$table: reviewed workflow column types/nullability changed.";
                }
                foreach ($expected['checks'] as $name => $check) {
                    if (($actual['checks'][$name] ?? null) !== $check) {
                        $errors[] = "$table: required enforced workflow CHECK $name is missing or changed.";
                    }
                }
                if (($actual['generated'] ?? []) !== $expected['generated']) {
                    $errors[] = "$table: reviewed stored workflow expressions changed.";
                }
            }
            // An added cascade must not erase history through a new reference.
            if ($table === 'approach_content_revisions') {
                $versionCheck = $actual['checks']['approach_revisions_version_check'] ?? [];
                $expression = str_replace(['`', '(', ')'], '', $versionCheck['clause'] ?? '');
                if (($actual['columns']['version_number'] ?? null) !== ['type' => 'int unsigned', 'nullable' => false]
                    || ($versionCheck['enforced'] ?? false) !== true
                    || $expression !== 'version_number>=1andversion_number<=4294967295') {
                    $errors[] = "$table: positive unsigned version range must retain its enforced CHECK.";
                }
            }
            if ($table === 'approach_changes') {
                foreach (['before_state', 'after_state'] as $column) {
                    if (($actual['columns'][$column] ?? null) !== ['type' => 'json', 'nullable' => false]) {
                        $errors[] = "$table: $column must remain non-null JSON.";
                    }
                }
                foreach (['approach_changes_state_check', 'approach_changes_owner_check'] as $name) {
                    $expected = $name === 'approach_changes_state_check'
                        ? "json_typebefore_state='object'andjson_typeafter_state='object'"
                        : "event='metadata_updated'andprevious_owner_idisnullandnew_owner_idisnullorevent='owner_changed'andprevious_owner_idisnotnullandnew_owner_idisnotnullandprevious_owner_id<>new_owner_id";
                    $expression = strtolower(str_replace(['`', '(', ')'], '', $actual['checks'][$name]['clause'] ?? ''));
                    if (($actual['checks'][$name]['enforced'] ?? false) !== true || $expression !== $expected) {
                        $errors[] = "$table: required enforced CHECK $name is missing or changed.";
                    }
                }
            }
            if (in_array($table, ['documents', 'document_versions'], true)) {
                $columns = ['id' => 'bigint unsigned', 'public_id' => 'char(26)', 'created_at' => 'timestamp'];
                $columns += $table === 'documents' ? ['title' => 'varchar(255)', 'description' => 'longtext']
                    : ['version_number' => 'int unsigned', 'storage_disk' => 'varchar(16)', 'storage_key' => 'varchar(255)',
                        'sha256' => 'char(64)', 'byte_size' => 'bigint unsigned', 'original_filename' => 'varchar(255)'];
                foreach ($columns as $column => $type) {
                    if (($actual['columns'][$column] ?? null) !== ['type' => $type, 'nullable' => false]) {
                        $errors[] = "$table: $column must retain its required type and non-nullability.";
                    }
                }
                if ($table === 'document_versions') {
                    foreach ([
                        'document_versions_number_check' => 'version_number>=1andversion_number<=4294967295',
                        'document_versions_disk_check' => "regexp_likestorage_disk,'^local$','c'",
                        'document_versions_hash_check' => "regexp_likesha256,'^[0-9a-f]{64}$','c'",
                        'document_versions_size_check' => 'byte_size>=0',
                    ] as $name => $required) {
                        $expression = str_replace(['`', '(', ')'], '', $actual['checks'][$name]['clause'] ?? '');
                        if (($actual['checks'][$name]['enforced'] ?? false) !== true || $expression !== $required) {
                            $errors[] = "$table: required enforced CHECK $name is missing or changed.";
                        }
                    }
                    if (($actual['columns']['detected_media_type'] ?? null) !== ['type' => 'varchar(255)', 'nullable' => true]) {
                        $errors[] = "$table: detected media type must remain optional technical metadata.";
                    }
                }
            }
            if (in_array($table, ['authorization_changes', 'organizational_unit_changes', 'organization_changes', 'approach_content_revisions', 'approach_changes', 'document_versions', 'document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'], true)) {
                foreach ($actual['foreign_keys'] as $key) {
                    if ($key['delete'] !== 'RESTRICT' || $key['update'] !== 'RESTRICT') {
                        $errors[] = "$table: every historical foreign key must remain RESTRICT on delete and update.";
                    }
                }
            }
        }
        $errors = array_values(array_unique($errors));
        sort($errors);

        return $errors;
    }
}
