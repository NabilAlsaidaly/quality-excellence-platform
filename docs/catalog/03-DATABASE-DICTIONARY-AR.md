# قاموس قاعدة البيانات

إصدار 1.0، تحقق 12 نوفمبر 2026، Issue [#71](https://github.com/NabilAlsaidaly/quality-excellence-platform/issues/71). المصدر المقروء `main@03c829fde71bbb11754ef657bf2edfe50253068e`؛ [CI بعد الدمج](https://github.com/NabilAlsaidaly/quality-excellence-platform/actions/runs/43289862828) ناجح.


هذا القاموس مستخرج من information_schema لقاعدة disposable مثبتة على MySQL8.4.13، مع مطابقة قائمة migrations للمصدر: 52 سجلًا و50 جدولًا، **لا وصفًا لقاعدة التطوير العادية**. الأدلة الأصلية خاصة. الأنواع أدناه أنواع MySQL الفعلية؛ SQLite بديل تحقق وليس نسخة مطابقة لكل SQL. المخطط [المتتبع](../../database/migrations) وعقد [العزل](../architecture/tenant-isolation-schema-contract.md) هما المرجع المستمر عند تغير المصدر.

BIGINT مفاتيح داخلية، وpublic_id ULID لا يمنح التفويض. غالبية بيانات المؤسسة تتبع organization_id؛ جداول Spatie والهوية والبنية العامة تُصنف بحسب العقد، فلا تفترض أن كل جدول يملك هذا العمود. لا soft-delete أو حذف مؤسسي شامل مُفترض. السجلات والإصدارات والاعتمادات مرتبطة بمراجع مقيدة؛ لا تستخدم rollback لاستعادة بيانات عمل.

```text
organizations -> memberships -> users
organizations -> units -> approaches -> content revisions
organizations -> documents -> document_versions
approach + stage + configuration -> submission -> review -> assessment
exact document_version -> maturity evidence
approved assessment -> reassessment attempt -> immutable decision/events
tenant + actor + resource/version + finite interval -> assignment
```

مخطط العلاقة يوضح المعنى؛ تعريف كل FK المركب بالترتيب موجود تحت الجدول. قيود MySQL لا تمنع كل كتابة عشوائية فوق حساب DBA؛ بعض الثبات تفرضه Actions/Models والاختبارات. لا تزعم منع UPDATE شامل في DB حيث لا يوجد trigger.

## approach_assignment_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `classification` | `varchar(12)` | NO | `TEST_ONLY` |   |
| `scenario` | `varchar(32)` | NO | `J03_LOCAL_APPROACH_001` |   |
| `assignment_id` | `bigint unsigned` | YES | `` |   |
| `approach_id` | `bigint unsigned` | YES | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `subject_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(24)` | NO | `` |   |
| `operation` | `varchar(32)` | NO | `` |   |
| `event_key` | `varchar(64)` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |

الفهارس: `approach_assignment_changes_actor_fk` INDEX (actor_id, organization_id); `approach_assignment_changes_assignment_fk` INDEX (organization_id, fixture_id, assignment_id, subject_id); `approach_assignment_changes_history_idx` INDEX (organization_id, fixture_id, approach_id, id); `approach_assignment_changes_key` UNIQUE (organization_id, fixture_id, event_key); `approach_assignment_changes_resource_fk` INDEX (organization_id, approach_id); `approach_assignment_changes_subject_fk` INDEX (subject_id, organization_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `approach_assignment_changes_actor_fk`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `approach_assignment_changes_assignment_fk`: (organization_id, fixture_id, assignment_id, subject_id) → `approach_assignments` (organization_id, fixture_id, id, grantee_id).

- FK `approach_assignment_changes_org_fk`: (organization_id) → `organizations` (id).

- FK `approach_assignment_changes_resource_fk`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `approach_assignment_changes_subject_fk`: (subject_id, organization_id) → `users` (id, organization_id).

- CHECK `approach_assignment_changes_event_check`: `(`event` in (_utf8mb4\'granted\',_utf8mb4\'revoked\',_utf8mb4\'expiration_observed\',_utf8mb4\'access_denied\'))`.

- CHECK `approach_assignment_changes_operation_check`: `(`operation` in (_utf8mb4\'quality\',_utf8mb4\'create\',_utf8mb4\'list\',_utf8mb4\'view\',_utf8mb4\'updateMetadata\',_utf8mb4\'editDraft\',_utf8mb4\'viewDraftHistory\',_utf8mb4\'viewAuditHistory\',_utf8mb4\'revokeContributor\'))`.

- CHECK `approach_assignment_changes_reference_check`: `((`event` = _utf8mb4\'access_denied\') or (`assignment_id` is not null))`.

- CHECK `approach_assignment_changes_test_check`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'J03_LOCAL_APPROACH_001\') and (char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

## approach_assignments

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(32)` | NO | `` |   |
| `classification` | `varchar(12)` | NO | `` |   |
| `grantee_id` | `bigint unsigned` | NO | `` |   |
| `issuer_id` | `bigint unsigned` | NO | `` |   |
| `operation` | `varchar(32)` | NO | `` |   |
| `scope_kind` | `varchar(16)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | YES | `` |   |
| `initial_contributor_id` | `bigint unsigned` | YES | `` |   |
| `scope_key` | `bigint` | YES | `` | STORED GENERATED generated: `coalesce(`approach_id`,0)` |
| `starts_at` | `datetime` | NO | `` |   |
| `expires_at` | `datetime` | NO | `` |   |
| `revoked_at` | `datetime` | YES | `` |   |
| `revoked_by` | `bigint unsigned` | YES | `` |   |
| `created_at` | `datetime` | NO | `` |   |

الفهارس: `approach_assignments_audit_identity` UNIQUE (organization_id, fixture_id, id, grantee_id); `approach_assignments_grantee_fk` INDEX (grantee_id, organization_id); `approach_assignments_issuer_fk` INDEX (issuer_id, organization_id); `approach_assignments_operation_unique` UNIQUE (organization_id, fixture_id, grantee_id, operation, scope_key); `approach_assignments_recipient_fk` INDEX (initial_contributor_id, organization_id); `approach_assignments_resource_fk` INDEX (organization_id, approach_id); `approach_assignments_resource_idx` INDEX (organization_id, fixture_id, approach_id, grantee_id, operation); `approach_assignments_revoker_fk` INDEX (revoked_by, organization_id); `approach_assignments_tenant_id` UNIQUE (organization_id, id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `approach_assignments_grantee_fk`: (grantee_id, organization_id) → `users` (id, organization_id).

- FK `approach_assignments_issuer_fk`: (issuer_id, organization_id) → `users` (id, organization_id).

- FK `approach_assignments_org_fk`: (organization_id) → `organizations` (id).

- FK `approach_assignments_recipient_fk`: (initial_contributor_id, organization_id) → `users` (id, organization_id).

- FK `approach_assignments_resource_fk`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `approach_assignments_revoker_fk`: (revoked_by, organization_id) → `users` (id, organization_id).

- CHECK `approach_assignments_recipient_check`: `(((`operation` = _utf8mb4\'create\') and (`initial_contributor_id` is not null)) or ((`operation` <> _utf8mb4\'create\') and (`initial_contributor_id` is null)))`.

- CHECK `approach_assignments_revocation_check`: `(((`revoked_at` is null) and (`revoked_by` is null)) or ((`revoked_at` is not null) and (`revoked_by` is not null) and (`revoked_at` >= `starts_at`)))`.

- CHECK `approach_assignments_scope_check`: `(((`scope_kind` = _utf8mb4\'institution\') and (`approach_id` is null) and (`operation` in (_utf8mb4\'quality\',_utf8mb4\'create\',_utf8mb4\'list\'))) or ((`scope_kind` = _utf8mb4\'approach\') and (`approach_id` is not null) and (`operation` in (_utf8mb4\'view\',_utf8mb4\'updateMetadata\',_utf8mb4\'editDraft\',_utf8mb4\'viewDraftHistory\',_utf8mb4\'viewAuditHistory\',_utf8mb4\'revokeContributor\'))))`.

- CHECK `approach_assignments_test_check`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'J03_LOCAL_APPROACH_001\') and (char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `approach_assignments_time_check`: `((`expires_at` > `starts_at`) and (`expires_at` = (`starts_at` + interval 24 hour)))`.

## approach_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(20)` | NO | `` |   |
| `before_state` | `json` | NO | `` |   |
| `after_state` | `json` | NO | `` |   |
| `previous_owner_id` | `bigint unsigned` | YES | `` |   |
| `new_owner_id` | `bigint unsigned` | YES | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `approach_changes_actor_fk` INDEX (actor_id); `approach_changes_history_idx` INDEX (organization_id, approach_id, id); `approach_changes_new_owner_fk` INDEX (organization_id, new_owner_id); `approach_changes_previous_owner_fk` INDEX (organization_id, previous_owner_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `approach_changes_actor_fk`: (actor_id) → `users` (id).

- FK `approach_changes_approach_fk`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `approach_changes_new_owner_fk`: (organization_id, new_owner_id) → `organizational_units` (organization_id, id).

- FK `approach_changes_organization_fk`: (organization_id) → `organizations` (id).

- FK `approach_changes_previous_owner_fk`: (organization_id, previous_owner_id) → `organizational_units` (organization_id, id).

- CHECK `approach_changes_event_check`: `(`event` in (_utf8mb4\'metadata_updated\',_utf8mb4\'owner_changed\'))`.

- CHECK `approach_changes_owner_check`: `(((`event` = _utf8mb4\'metadata_updated\') and (`previous_owner_id` is null) and (`new_owner_id` is null)) or ((`event` = _utf8mb4\'owner_changed\') and (`previous_owner_id` is not null) and (`new_owner_id` is not null) and (`previous_owner_id` <> `new_owner_id`)))`.

- CHECK `approach_changes_state_check`: `((json_type(`before_state`) = _utf8mb4\'OBJECT\') and (json_type(`after_state`) = _utf8mb4\'OBJECT\'))`.

## approach_content_revisions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `version_number` | `int unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `body` | `longtext` | NO | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `approach_revision_workflow_scope` UNIQUE (organization_id, approach_id, id); `approach_revisions_actor_fk` INDEX (actor_id); `approach_revisions_version_unique` UNIQUE (approach_id, version_number); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `approach_revisions_actor_fk`: (actor_id) → `users` (id).

- FK `approach_revisions_approach_fk`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `approach_revisions_organization_fk`: (organization_id) → `organizations` (id).

- CHECK `approach_revisions_version_check`: `((`version_number` >= 1) and (`version_number` <= 4294967295))`.

## approaches

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `owning_unit_id` | `bigint unsigned` | NO | `` |   |
| `created_by` | `bigint unsigned` | NO | `` |   |
| `name` | `varchar(255)` | NO | `` |   |
| `description` | `longtext` | NO | `` |   |
| `purpose` | `longtext` | NO | `` |   |
| `scope` | `longtext` | NO | `` |   |
| `objectives` | `longtext` | YES | `` |   |
| `review_frequency` | `longtext` | YES | `` |   |
| `created_at` | `timestamp` | NO | `` |   |
| `updated_at` | `timestamp` | NO | `` |   |

الفهارس: `approaches_creator_fk` INDEX (created_by); `approaches_organization_created_idx` INDEX (organization_id, created_at, id); `approaches_organization_id_unique` UNIQUE (organization_id, id); `approaches_organization_name_idx` INDEX (organization_id, name, id); `approaches_owner_fk` INDEX (organization_id, owning_unit_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `approaches_creator_fk`: (created_by) → `users` (id).

- FK `approaches_organization_fk`: (organization_id) → `organizations` (id).

- FK `approaches_owner_fk`: (organization_id, owning_unit_id) → `organizational_units` (organization_id, id).

## authorization_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(40)` | NO | `` |   |
| `role_id` | `bigint unsigned` | YES | `` |   |
| `subject_user_id` | `bigint unsigned` | YES | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `authorization_changes_actor_fk` INDEX (actor_id); `authorization_changes_organization_fk` INDEX (organization_id); `authorization_changes_subject_fk` INDEX (subject_user_id); `PRIMARY` UNIQUE (id).

- FK `authorization_changes_actor_fk`: (actor_id) → `users` (id).

- FK `authorization_changes_organization_fk`: (organization_id) → `organizations` (id).

- FK `authorization_changes_subject_fk`: (subject_user_id) → `users` (id).

## cache

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `key` | `varchar(255)` | NO | `` |   |
| `value` | `mediumtext` | NO | `` |   |
| `expiration` | `bigint` | NO | `` |   |

الفهارس: `cache_expiration_index` INDEX (expiration); `PRIMARY` UNIQUE (key).

## cache_locks

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `key` | `varchar(255)` | NO | `` |   |
| `owner` | `varchar(255)` | NO | `` |   |
| `expiration` | `bigint` | NO | `` |   |

الفهارس: `cache_locks_expiration_index` INDEX (expiration); `PRIMARY` UNIQUE (key).

## document_demo_availability_decisions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `DOCUMENT_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `document_id` | `bigint unsigned` | NO | `` |   |
| `version_id` | `bigint unsigned` | NO | `` |   |
| `intake_id` | `bigint unsigned` | NO | `` |   |
| `reviewer_id` | `bigint unsigned` | NO | `` |   |
| `authority_id` | `bigint unsigned` | NO | `` |   |
| `authority_operation` | `varchar(32)` | YES | `` | STORED GENERATED generated: `_utf8mb4\'release_demo_unscanned\'` |
| `state` | `varchar(32)` | NO | `` |   |
| `request_key` | `char(26)` | NO | `` |   |
| `reason` | `text` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |

الفهارس: `demo_availability_authority` INDEX (organization_id, fixture_id, authority_id, reviewer_id, authority_operation, document_id, version_id); `demo_availability_intake` INDEX (organization_id, fixture_id, intake_id, document_id, version_id); `demo_availability_request` UNIQUE (organization_id, fixture_id, reviewer_id, request_key); `demo_availability_reviewer` INDEX (reviewer_id, organization_id); `demo_availability_tenant` UNIQUE (organization_id, id); `demo_availability_version` UNIQUE (organization_id, fixture_id, document_id, version_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `demo_availability_authority`: (organization_id, fixture_id, authority_id, reviewer_id, authority_operation, document_id, version_id) → `document_file_operation_assignments` (organization_id, fixture_id, id, grantee_id, operation, document_id, version_id).

- FK `demo_availability_intake`: (organization_id, fixture_id, intake_id, document_id, version_id) → `document_intakes` (organization_id, fixture_id, id, document_id, version_id).

- FK `demo_availability_reviewer`: (reviewer_id, organization_id) → `users` (id, organization_id).

- CHECK `demo_availability_class`: `((`scenario` = _utf8mb4\'DOCUMENT_LOCAL_001\') and (`classification` = _utf8mb4\'TEST_ONLY\'))`.

- CHECK `demo_availability_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `demo_availability_reason`: `(length(`reason`) > 0)`.

- CHECK `demo_availability_state`: `(`state` in (_utf8mb4\'available_demo_unscanned\',_utf8mb4\'denied_demo_unscanned\'))`.

## document_file_operation_assignments

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `DOCUMENT_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `grantee_id` | `bigint unsigned` | NO | `` |   |
| `issuer_id` | `bigint unsigned` | NO | `` |   |
| `document_public_id` | `char(26)` | NO | `` |   |
| `version_public_id` | `char(26)` | YES | `` |   |
| `document_id` | `bigint unsigned` | YES | `` |   |
| `version_id` | `bigint unsigned` | YES | `` |   |
| `operation` | `varchar(32)` | NO | `` |   |
| `starts_at` | `datetime` | NO | `` |   |
| `expires_at` | `datetime` | NO | `` |   |
| `revoked_at` | `datetime` | YES | `` |   |
| `revoked_by` | `bigint unsigned` | YES | `` |   |
| `version_scope` | `char(26)` | YES | `` | STORED GENERATED generated: `coalesce(`version_public_id`,_utf8mb4\'00000000000000000000000000\')` |

الفهارس: `df_grant_demo_authority` UNIQUE (organization_id, fixture_id, id, grantee_id, operation, document_id, version_id); `df_grant_doc` INDEX (organization_id, document_id, document_public_id); `df_grant_grantee` INDEX (grantee_id, organization_id); `df_grant_identity` UNIQUE (organization_id, fixture_id, id, grantee_id); `df_grant_issuer` INDEX (issuer_id, organization_id); `df_grant_once` UNIQUE (organization_id, fixture_id, grantee_id, operation, document_public_id, version_scope); `df_grant_revoker` INDEX (revoked_by, organization_id); `df_grant_tenant` UNIQUE (organization_id, id); `df_grant_version` INDEX (organization_id, document_id, version_id, version_public_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `df_grant_doc`: (organization_id, document_id, document_public_id) → `documents` (organization_id, id, public_id).

- FK `df_grant_grantee`: (grantee_id, organization_id) → `users` (id, organization_id).

- FK `df_grant_issuer`: (issuer_id, organization_id) → `users` (id, organization_id).

- FK `df_grant_org`: (organization_id) → `organizations` (id).

- FK `df_grant_revoker`: (revoked_by, organization_id) → `users` (id, organization_id).

- FK `df_grant_version`: (organization_id, document_id, version_id, version_public_id) → `document_versions` (organization_id, document_id, id, public_id).

- CHECK `df_grant_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'DOCUMENT_LOCAL_001\'))`.

- CHECK `df_grant_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_ascii\'[^0-9a-f]\'))))`.

- CHECK `df_grant_period`: `(((`starts_at` + interval 24 hour) is not null) and (`expires_at` > `starts_at`) and (`expires_at` = (`starts_at` + interval 24 hour)))`.

- CHECK `df_grant_revocation`: `(((`revoked_at` is null) and (`revoked_by` is null)) or ((`revoked_at` is not null) and (`revoked_by` is not null) and (`revoked_at` >= `starts_at`)))`.

- CHECK `df_grant_scope`: `(((`operation` in (_utf8mb4\'view\',_utf8mb4\'metadata\',_utf8mb4\'history\')) and (`version_public_id` is null) and (`version_id` is null)) or ((`operation` in (_utf8mb4\'upload_real\',_utf8mb4\'append_real\',_utf8mb4\'release_real\',_utf8mb4\'download_real\',_utf8mb4\'release_demo_unscanned\',_utf8mb4\'download_demo_unscanned\')) and (`version_public_id` is not null)))`.

- CHECK `df_grant_uint`: `((`organization_id` > 0) and (`grantee_id` > 0) and (`issuer_id` > 0) and ((`document_id` is null) or (`document_id` > 0)) and ((`version_id` is null) or (`version_id` > 0)) and ((`revoked_by` is null) or (`revoked_by` > 0)))`.

- CHECK `df_grant_version_parent`: `((`version_id` is null) or (`document_id` is not null))`.

## document_file_operation_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `DOCUMENT_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `assignment_id` | `bigint unsigned` | YES | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `subject_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(24)` | NO | `` |   |
| `event_key` | `varchar(100)` | NO | `` |   |
| `details_json` | `json` | NO | `` |   |

الفهارس: `df_change_actor` INDEX (actor_id, organization_id); `df_change_event` UNIQUE (organization_id, fixture_id, event_key); `df_change_grant` INDEX (organization_id, fixture_id, assignment_id, subject_id); `df_change_subject` INDEX (subject_id, organization_id); `df_change_tenant` UNIQUE (organization_id, id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `df_change_actor`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `df_change_grant`: (organization_id, fixture_id, assignment_id, subject_id) → `document_file_operation_assignments` (organization_id, fixture_id, id, grantee_id).

- FK `df_change_org`: (organization_id) → `organizations` (id).

- FK `df_change_subject`: (subject_id, organization_id) → `users` (id, organization_id).

- CHECK `df_change_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'DOCUMENT_LOCAL_001\'))`.

- CHECK `df_change_details`: `(json_type(`details_json`) = _utf8mb4\'OBJECT\')`.

- CHECK `df_change_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `df_change_kind`: `(`event` in (_utf8mb4\'granted\',_utf8mb4\'revoked\',_utf8mb4\'expiration_observed\',_utf8mb4\'access_denied\'))`.

- CHECK `df_change_reference`: `((`assignment_id` is not null) or (`event` = _utf8mb4\'access_denied\'))`.

- CHECK `df_change_uint`: `((`organization_id` > 0) and (`actor_id` > 0) and (`subject_id` > 0) and ((`assignment_id` is null) or (`assignment_id` > 0)))`.

## document_intake_events

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `intake_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | YES | `` |   |
| `generation` | `int unsigned` | NO | `` |   |
| `request_key` | `char(26)` | NO | `` |   |
| `event` | `varchar(24)` | NO | `` |   |
| `details_json` | `json` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `DOCUMENT_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |

الفهارس: `actor_id` INDEX (actor_id, organization_id); `die_history` INDEX (organization_id, intake_id, id); `organization_id` UNIQUE (organization_id, fixture_id, id); `organization_id_2` UNIQUE (organization_id, id); `organization_id_3` UNIQUE (organization_id, intake_id, request_key); `organization_id_4` INDEX (organization_id, fixture_id, intake_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `document_intake_events_ibfk_1`: (organization_id, fixture_id, intake_id) → `document_intakes` (organization_id, fixture_id, id).

- FK `document_intake_events_ibfk_2`: (actor_id, organization_id) → `users` (id, organization_id).

- CHECK `document_intake_events_chk_1`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'DOCUMENT_LOCAL_001\'))`.

- CHECK `document_intake_events_chk_2`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `document_intake_events_chk_3`: `((`generation` > 0) and (`generation` <= 4294967295))`.

- CHECK `document_intake_events_chk_4`: `(`event` in (_utf8mb4\'registered\',_utf8mb4\'scan_started\',_utf8mb4\'scan_clean\',_utf8mb4\'scan_suspect\',_utf8mb4\'scan_failed\',_utf8mb4\'released\',_utf8mb4\'release_denied\',_utf8mb4\'reconciled\',_utf8mb4\'downloaded\',_utf8mb4\'access_denied\'))`.

- CHECK `document_intake_events_chk_5`: `(json_type(`details_json`) = _utf8mb4\'OBJECT\')`.

## document_intakes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `document_id` | `bigint unsigned` | NO | `` |   |
| `version_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `request_key` | `char(26)` | NO | `` |   |
| `request_hash` | `char(64)` | NO | `` |   |
| `state` | `varchar(16)` | NO | `` |   |
| `scan_result` | `varchar(16)` | YES | `` |   |
| `generation` | `int unsigned` | NO | `1` |   |
| `lease_hash` | `char(64)` | YES | `` |   |
| `lease_expires_at` | `datetime` | YES | `` |   |
| `released_at` | `datetime` | YES | `` |   |
| `release_actor_id` | `bigint unsigned` | YES | `` |   |
| `release_authority_ref` | `varchar(128)` | YES | `` |   |
| `inspected_media_type` | `varchar(128)` | NO | `` |   |
| `limits_json` | `json` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |
| `updated_at` | `datetime` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `DOCUMENT_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |

الفهارس: `actor_id` INDEX (actor_id, organization_id); `di_work` INDEX (state, lease_expires_at, id); `intake_demo_exact_scope` UNIQUE (organization_id, fixture_id, id, document_id, version_id); `organization_id` UNIQUE (organization_id, fixture_id, id); `organization_id_2` UNIQUE (organization_id, id); `organization_id_3` UNIQUE (organization_id, actor_id, request_key); `organization_id_4` UNIQUE (organization_id, document_id, version_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id); `release_actor_id` INDEX (release_actor_id, organization_id).

- FK `document_intakes_ibfk_1`: (organization_id, document_id, version_id) → `document_versions` (organization_id, document_id, id).

- FK `document_intakes_ibfk_2`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `document_intakes_ibfk_3`: (release_actor_id, organization_id) → `users` (id, organization_id).

- CHECK `document_intakes_chk_1`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'DOCUMENT_LOCAL_001\'))`.

- CHECK `document_intakes_chk_2`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_ascii\'[^0-9a-f]\'))))`.

- CHECK `document_intakes_chk_3`: `((`generation` > 0) and (`generation` <= 4294967295))`.

- CHECK `document_intakes_chk_4`: `((char_length(`request_hash`) = 64) and (not(regexp_like(`request_hash`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `document_intakes_chk_5`: `(`state` in (_utf8mb4\'pending\',_utf8mb4\'scanning\',_utf8mb4\'clean\',_utf8mb4\'suspect\',_utf8mb4\'failed\',_utf8mb4\'released\',_utf8mb4\'release_denied\'))`.

- CHECK `document_intakes_chk_6`: `(((`state` in (_utf8mb4\'pending\',_utf8mb4\'scanning\')) and (`scan_result` is null)) or ((`state` in (_utf8mb4\'clean\',_utf8mb4\'released\',_utf8mb4\'release_denied\')) and (`scan_result` is not null) and (`scan_result` = _utf8mb4\'clean\')) or ((`state` = _utf8mb4\'suspect\') and (`scan_result` is not null) and (`scan_result` = _utf8mb4\'suspect\')) or ((`state` = _utf8mb4\'failed\') and (`scan_result` is not null) and (`scan_result` = _utf8mb4\'unavailable\')))`.

- CHECK `document_intakes_chk_7`: `(((`state` = _utf8mb4\'scanning\') and (`lease_hash` is not null) and (`lease_expires_at` is not null)) or ((`state` <> _utf8mb4\'scanning\') and (`lease_hash` is null) and (`lease_expires_at` is null)))`.

- CHECK `document_intakes_chk_8`: `(((`state` = _utf8mb4\'released\') and (`released_at` is not null) and (`release_actor_id` is not null) and (`release_actor_id` <> `actor_id`) and (`release_authority_ref` is not null) and (char_length(trim(`release_authority_ref`)) > 0)) or ((`state` <> _utf8mb4\'released\') and (`released_at` is null) and (`release_actor_id` is null) and (`release_authority_ref` is null)))`.

- CHECK `document_intakes_chk_9`: `(json_type(`limits_json`) = _utf8mb4\'OBJECT\')`.

## document_metadata_revisions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `document_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `version_number` | `int unsigned` | NO | `` |   |
| `title` | `varchar(255)` | NO | `` |   |
| `description` | `longtext` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |

الفهارس: `doc_meta_actor_fk` INDEX (actor_id, organization_id); `doc_meta_public` UNIQUE (public_id); `doc_meta_tenant` UNIQUE (organization_id, id); `doc_meta_version` UNIQUE (organization_id, document_id, version_number); `PRIMARY` UNIQUE (id).

- FK `doc_meta_actor_fk`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `doc_meta_document_fk`: (organization_id, document_id) → `documents` (organization_id, id).

- FK `doc_meta_org_fk`: (organization_id) → `organizations` (id).

- CHECK `doc_meta_description`: `(char_length(trim(`description`)) > 0)`.

- CHECK `doc_meta_number`: `((`version_number` > 0) and (`version_number` <= 4294967295))`.

- CHECK `doc_meta_title`: `(char_length(trim(`title`)) > 0)`.

## document_versions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `document_id` | `bigint unsigned` | NO | `` |   |
| `version_number` | `int unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `storage_disk` | `varchar(16)` | NO | `` |   |
| `storage_key` | `varchar(255)` | NO | `` |   |
| `sha256` | `char(64)` | NO | `` |   |
| `byte_size` | `bigint unsigned` | NO | `` |   |
| `original_filename` | `varchar(255)` | NO | `` |   |
| `detected_media_type` | `varchar(255)` | YES | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `document_version_workflow_scope` UNIQUE (organization_id, document_id, id); `document_versions_actor_fk` INDEX (actor_id); `document_versions_number_unique` UNIQUE (document_id, version_number); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id); `storage_key` UNIQUE (storage_key); `versions_closure_public_scope` UNIQUE (organization_id, document_id, id, public_id).

- FK `document_versions_actor_fk`: (actor_id) → `users` (id).

- FK `document_versions_document_fk`: (organization_id, document_id) → `documents` (organization_id, id).

- FK `document_versions_organization_fk`: (organization_id) → `organizations` (id).

- CHECK `document_versions_disk_check`: `regexp_like(`storage_disk`,_utf8mb4\'^local$\',_utf8mb4\'c\')`.

- CHECK `document_versions_hash_check`: `regexp_like(`sha256`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\')`.

- CHECK `document_versions_number_check`: `((`version_number` >= 1) and (`version_number` <= 4294967295))`.

- CHECK `document_versions_size_check`: `(`byte_size` >= 0)`.

## document_workspace_assignment_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `DOCUMENT_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `assignment_id` | `bigint unsigned` | YES | `` |   |
| `document_id` | `bigint unsigned` | YES | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `subject_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(24)` | NO | `` |   |
| `operation` | `varchar(20)` | NO | `` |   |
| `event_key` | `varchar(100)` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |
| `scope_key` | `bigint unsigned` | YES | `` | STORED GENERATED generated: `coalesce(`document_id`,0)` |

الفهارس: `doc_ws_change_actor_fk` INDEX (actor_id, organization_id); `doc_ws_change_assignment_fk` INDEX (organization_id, fixture_id, assignment_id, subject_id, scope_key, operation); `doc_ws_change_document_fk` INDEX (organization_id, document_id); `doc_ws_change_event` UNIQUE (organization_id, fixture_id, event_key); `doc_ws_change_history_idx` INDEX (organization_id, fixture_id, document_id, id); `doc_ws_change_public` UNIQUE (public_id); `doc_ws_change_subject_fk` INDEX (subject_id, organization_id); `doc_ws_change_tenant` UNIQUE (organization_id, id); `PRIMARY` UNIQUE (id).

- FK `doc_ws_change_actor_fk`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `doc_ws_change_assignment_fk`: (organization_id, fixture_id, assignment_id, subject_id, scope_key, operation) → `document_workspace_assignments` (organization_id, fixture_id, id, grantee_id, scope_key, operation).

- FK `doc_ws_change_document_fk`: (organization_id, document_id) → `documents` (organization_id, id).

- FK `doc_ws_change_org_fk`: (organization_id) → `organizations` (id).

- FK `doc_ws_change_subject_fk`: (subject_id, organization_id) → `users` (id, organization_id).

- CHECK `doc_ws_change_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'DOCUMENT_LOCAL_001\'))`.

- CHECK `doc_ws_change_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `doc_ws_change_kind`: `(`event` in (_utf8mb4\'granted\',_utf8mb4\'revoked\',_utf8mb4\'expiration_observed\',_utf8mb4\'access_denied\'))`.

- CHECK `doc_ws_change_reference`: `((`assignment_id` is not null) or (`event` = _utf8mb4\'access_denied\'))`.

- CHECK `doc_ws_change_scope`: `(((`document_id` is null) and (`operation` in (_utf8mb4\'create\',_utf8mb4\'list\'))) or ((`document_id` is not null) and (`operation` in (_utf8mb4\'view\',_utf8mb4\'metadata\',_utf8mb4\'append\',_utf8mb4\'history\'))))`.

## document_workspace_assignments

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `DOCUMENT_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `grantee_id` | `bigint unsigned` | NO | `` |   |
| `issuer_id` | `bigint unsigned` | NO | `` |   |
| `document_id` | `bigint unsigned` | YES | `` |   |
| `scope_kind` | `varchar(16)` | NO | `` |   |
| `operation` | `varchar(20)` | NO | `` |   |
| `starts_at` | `datetime` | NO | `` |   |
| `expires_at` | `datetime` | NO | `` |   |
| `revoked_at` | `datetime` | YES | `` |   |
| `revoked_by` | `bigint unsigned` | YES | `` |   |
| `created_at` | `datetime` | NO | `` |   |
| `scope_key` | `bigint unsigned` | YES | `` | STORED GENERATED generated: `coalesce(`document_id`,0)` |

الفهارس: `doc_ws_assignment_audit_identity` UNIQUE (organization_id, fixture_id, id, grantee_id, scope_key, operation); `doc_ws_assignment_document_fk` INDEX (organization_id, document_id); `doc_ws_assignment_grantee_fk` INDEX (grantee_id, organization_id); `doc_ws_assignment_issuer_fk` INDEX (issuer_id, organization_id); `doc_ws_assignment_operation` UNIQUE (organization_id, fixture_id, grantee_id, operation, scope_key); `doc_ws_assignment_public` UNIQUE (public_id); `doc_ws_assignment_resource_idx` INDEX (organization_id, fixture_id, document_id, grantee_id, operation); `doc_ws_assignment_revoker_fk` INDEX (revoked_by, organization_id); `doc_ws_assignment_tenant` UNIQUE (organization_id, id); `PRIMARY` UNIQUE (id).

- FK `doc_ws_assignment_document_fk`: (organization_id, document_id) → `documents` (organization_id, id).

- FK `doc_ws_assignment_grantee_fk`: (grantee_id, organization_id) → `users` (id, organization_id).

- FK `doc_ws_assignment_issuer_fk`: (issuer_id, organization_id) → `users` (id, organization_id).

- FK `doc_ws_assignment_org_fk`: (organization_id) → `organizations` (id).

- FK `doc_ws_assignment_revoker_fk`: (revoked_by, organization_id) → `users` (id, organization_id).

- CHECK `doc_ws_assignment_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'DOCUMENT_LOCAL_001\'))`.

- CHECK `doc_ws_assignment_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `doc_ws_assignment_period`: `(((`starts_at` + interval 24 hour) is not null) and (`expires_at` > `starts_at`) and (`expires_at` = (`starts_at` + interval 24 hour)))`.

- CHECK `doc_ws_assignment_revoke`: `(((`revoked_at` is null) and (`revoked_by` is null)) or ((`revoked_at` is not null) and (`revoked_by` is not null) and (`revoked_at` >= `starts_at`)))`.

- CHECK `doc_ws_assignment_scope`: `(((`scope_kind` = _utf8mb4\'institution\') and (`document_id` is null) and (`operation` in (_utf8mb4\'create\',_utf8mb4\'list\'))) or ((`scope_kind` = _utf8mb4\'document\') and (`document_id` is not null) and (`operation` in (_utf8mb4\'view\',_utf8mb4\'metadata\',_utf8mb4\'append\',_utf8mb4\'history\'))))`.

## documents

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `created_by` | `bigint unsigned` | NO | `` |   |
| `title` | `varchar(255)` | NO | `` |   |
| `description` | `longtext` | NO | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `documents_closure_public_scope` UNIQUE (organization_id, id, public_id); `documents_creator_fk` INDEX (created_by); `documents_organization_created_idx` INDEX (organization_id, created_at, id); `documents_organization_id_unique` UNIQUE (organization_id, id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `documents_creator_fk`: (created_by) → `users` (id).

- FK `documents_organization_fk`: (organization_id) → `organizations` (id).

## failed_jobs

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `uuid` | `varchar(255)` | NO | `` |   |
| `connection` | `text` | NO | `` |   |
| `queue` | `text` | NO | `` |   |
| `payload` | `longtext` | NO | `` |   |
| `exception` | `longtext` | NO | `` |   |
| `failed_at` | `timestamp` | NO | `CURRENT_TIMESTAMP` | DEFAULT_GENERATED  |

الفهارس: `failed_jobs_uuid_unique` UNIQUE (uuid); `PRIMARY` UNIQUE (id).

## institution_password_reset_tokens

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `account_id` | `bigint unsigned` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `token` | `varchar(255)` | NO | `` |   |
| `credential_hash` | `char(64)` | NO | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `institution_reset_account_fk` INDEX (account_id, organization_id); `PRIMARY` UNIQUE (account_id).

- FK `institution_reset_account_fk`: (account_id, organization_id) → `users` (id, organization_id).

## job_batches

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `varchar(255)` | NO | `` |   |
| `name` | `varchar(255)` | NO | `` |   |
| `total_jobs` | `int` | NO | `` |   |
| `pending_jobs` | `int` | NO | `` |   |
| `failed_jobs` | `int` | NO | `` |   |
| `failed_job_ids` | `longtext` | NO | `` |   |
| `options` | `mediumtext` | YES | `` |   |
| `cancelled_at` | `int` | YES | `` |   |
| `created_at` | `int` | NO | `` |   |
| `finished_at` | `int` | YES | `` |   |

الفهارس: `PRIMARY` UNIQUE (id).

## jobs

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `queue` | `varchar(255)` | NO | `` |   |
| `payload` | `longtext` | NO | `` |   |
| `attempts` | `tinyint unsigned` | NO | `` |   |
| `reserved_at` | `int unsigned` | YES | `` |   |
| `available_at` | `int unsigned` | NO | `` |   |
| `created_at` | `int unsigned` | NO | `` |   |

الفهارس: `jobs_queue_index` INDEX (queue); `PRIMARY` UNIQUE (id).

## maturity_reassessment_attempts

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |
| `initial_assessment_id` | `bigint unsigned` | NO | `` |   |
| `previous_attempt_id` | `bigint unsigned` | YES | `` |   |
| `attempt_number` | `int unsigned` | NO | `` |   |
| `previous_attempt_number` | `int unsigned` | YES | `` | STORED GENERATED generated: `(case when (`attempt_number` > 1) then (`attempt_number` - 1) else NULL end)` |
| `previous_approved_state` | `varchar(16)` | YES | `` | STORED GENERATED generated: `_utf8mb4\'approved\'` |
| `author_id` | `bigint unsigned` | NO | `` |   |
| `configuration_id` | `bigint unsigned` | NO | `` |   |
| `approved_submission_id` | `bigint unsigned` | YES | `` |   |
| `approved_review_id` | `bigint unsigned` | YES | `` |   |
| `request_key` | `char(26)` | NO | `` |   |
| `request_hash` | `char(64)` | NO | `` |   |
| `state` | `varchar(16)` | NO | `` |   |
| `revision` | `int unsigned` | NO | `` |   |
| `active_slot` | `tinyint unsigned` | YES | `` | STORED GENERATED generated: `(case when (`state` = _utf8mb4\'open\') then 1 else NULL end)` |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |

الفهارس: `author_id` INDEX (author_id, organization_id); `organization_id` UNIQUE (organization_id, fixture_id, id); `organization_id_10` INDEX (organization_id, fixture_id, approach_id, stage_id, initial_assessment_id); `organization_id_11` INDEX (organization_id, fixture_id, approach_id, stage_id, previous_attempt_id, previous_attempt_number, previous_approved_state); `organization_id_12` INDEX (organization_id, fixture_id, approach_id, stage_id, configuration_id); `organization_id_2` UNIQUE (organization_id, id); `organization_id_3` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id); `organization_id_4` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id, state); `organization_id_5` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id, approved_submission_id, approved_review_id); `organization_id_6` UNIQUE (organization_id, fixture_id, approach_id, stage_id, attempt_number); `organization_id_7` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id, attempt_number, state); `organization_id_8` UNIQUE (organization_id, fixture_id, approach_id, stage_id, active_slot); `organization_id_9` UNIQUE (organization_id, author_id, request_key); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `maturity_reassessment_attempts_ibfk_1`: (organization_id, fixture_id, approach_id, stage_id, initial_assessment_id) → `maturity_workflow_assessments` (organization_id, fixture_id, approach_id, stage_id, id).

- FK `maturity_reassessment_attempts_ibfk_2`: (organization_id, fixture_id, approach_id, stage_id, previous_attempt_id, previous_attempt_number, previous_approved_state) → `maturity_reassessment_attempts` (organization_id, fixture_id, approach_id, stage_id, id, attempt_number, state).

- FK `maturity_reassessment_attempts_ibfk_3`: (organization_id, fixture_id, approach_id, stage_id, configuration_id) → `maturity_workflow_configurations` (organization_id, fixture_id, approach_id, stage_id, id).

- FK `maturity_reassessment_attempts_ibfk_4`: (author_id, organization_id) → `users` (id, organization_id).

- CHECK `maturity_reassessment_attempts_chk_1`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `maturity_reassessment_attempts_chk_2`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `maturity_reassessment_attempts_chk_3`: `((`revision` > 0) and (`revision` <= 4294967295))`.

- CHECK `maturity_reassessment_attempts_chk_4`: `(`state` in (_utf8mb4\'open\',_utf8mb4\'approved\'))`.

- CHECK `maturity_reassessment_attempts_chk_5`: `(((`state` = _utf8mb4\'open\') and (`approved_submission_id` is null) and (`approved_review_id` is null)) or ((`state` = _utf8mb4\'approved\') and (`approved_submission_id` is not null) and (`approved_review_id` is not null)))`.

- CHECK `maturity_reassessment_attempts_chk_6`: `((`attempt_number` > 0) and (`attempt_number` <= 4294967295))`.

- CHECK `maturity_reassessment_attempts_chk_7`: `(((`attempt_number` = 1) and (`previous_attempt_id` is null)) or ((`attempt_number` > 1) and (`previous_attempt_id` is not null)))`.

- CHECK `maturity_reassessment_attempts_chk_8`: `((char_length(`request_hash`) = 64) and (not(regexp_like(`request_hash`,_utf8mb4\'[^0-9a-f]\'))))`.

## maturity_reassessment_decisions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |
| `attempt_id` | `bigint unsigned` | NO | `` |   |
| `submission_id` | `bigint unsigned` | NO | `` |   |
| `review_id` | `bigint unsigned` | NO | `` |   |
| `reviewer_id` | `bigint unsigned` | NO | `` |   |
| `approved_state` | `varchar(20)` | YES | `` | STORED GENERATED generated: `_utf8mb4\'approved\'` |
| `attempt_approved_state` | `varchar(16)` | YES | `` | STORED GENERATED generated: `_utf8mb4\'approved\'` |
| `selected_band_index` | `int unsigned` | NO | `` |   |
| `snapshot_json` | `json` | NO | `` |   |
| `reason` | `longtext` | NO | `` |   |
| `policy_ref` | `varchar(128)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |

الفهارس: `mr_dec_approved_attempt_fk` INDEX (organization_id, fixture_id, approach_id, stage_id, attempt_id, attempt_approved_state); `mr_dec_attempt_pair_fk` INDEX (organization_id, fixture_id, approach_id, stage_id, attempt_id, submission_id, review_id); `organization_id` UNIQUE (organization_id, fixture_id, id); `organization_id_2` UNIQUE (organization_id, id); `organization_id_3` UNIQUE (organization_id, fixture_id, approach_id, stage_id, attempt_id); `organization_id_4` UNIQUE (organization_id, fixture_id, approach_id, stage_id, review_id); `organization_id_5` INDEX (organization_id, fixture_id, approach_id, stage_id, review_id, submission_id, reviewer_id, approved_state); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id); `reviewer_id` INDEX (reviewer_id, organization_id).

- FK `maturity_reassessment_decisions_ibfk_1`: (organization_id, fixture_id, approach_id, stage_id, review_id, submission_id, reviewer_id, approved_state) → `review_maturity_reviews` (organization_id, fixture_id, approach_id, stage_id, id, submission_id, reviewer_id, state).

- FK `maturity_reassessment_decisions_ibfk_2`: (reviewer_id, organization_id) → `users` (id, organization_id).

- FK `mr_dec_approved_attempt_fk`: (organization_id, fixture_id, approach_id, stage_id, attempt_id, attempt_approved_state) → `maturity_reassessment_attempts` (organization_id, fixture_id, approach_id, stage_id, id, state).

- FK `mr_dec_attempt_pair_fk`: (organization_id, fixture_id, approach_id, stage_id, attempt_id, submission_id, review_id) → `maturity_reassessment_attempts` (organization_id, fixture_id, approach_id, stage_id, id, approved_submission_id, approved_review_id).

- CHECK `maturity_reassessment_decisions_chk_1`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `maturity_reassessment_decisions_chk_2`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `maturity_reassessment_decisions_chk_3`: `((char_length(trim(`reason`)) > 0) and (char_length(trim(`policy_ref`)) > 0))`.

- CHECK `maturity_reassessment_decisions_chk_4`: `(`selected_band_index` >= 0)`.

- CHECK `maturity_reassessment_decisions_chk_5`: `((json_type(`snapshot_json`) = _utf8mb4\'OBJECT\') and json_contains_path(`snapshot_json`,_utf8mb4\'all\',_utf8mb4\'$.scale\',_utf8mb4\'$.selected_band_index\',_utf8mb4\'$.answers\') and (json_type(json_extract(`snapshot_json`,_utf8mb4\'$.scale\')) = _utf8mb4\'ARRAY\') and (json_type(json_extract(`snapshot_json`,_utf8mb4\'$.selected_band_index\')) = _utf8mb4\'INTEGER\') and (`selected_band_index` < json_length(json_extract(`snapshot_json`,_utf8mb4\'$.scale\'))) and (json_extract(`snapshot_json`,_utf8mb4\'$.selected_band_index\') = `selected_band_index`))`.

## maturity_reassessment_events

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `created_at` | `datetime` | NO | `` |   |
| `attempt_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `submission_id` | `bigint unsigned` | YES | `` |   |
| `request_key` | `char(26)` | NO | `` |   |
| `event` | `varchar(24)` | NO | `` |   |
| `details_json` | `json` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |

الفهارس: `actor_id` INDEX (actor_id, organization_id); `organization_id` UNIQUE (organization_id, fixture_id, id); `organization_id_2` UNIQUE (organization_id, id); `organization_id_3` UNIQUE (organization_id, fixture_id, approach_id, stage_id, attempt_id, request_key); `organization_id_4` INDEX (organization_id, fixture_id, approach_id, stage_id, submission_id); `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id).

- FK `maturity_reassessment_events_ibfk_1`: (organization_id, fixture_id, approach_id, stage_id, attempt_id) → `maturity_reassessment_attempts` (organization_id, fixture_id, approach_id, stage_id, id).

- FK `maturity_reassessment_events_ibfk_2`: (organization_id, fixture_id, approach_id, stage_id, submission_id) → `maturity_workflow_submissions` (organization_id, fixture_id, approach_id, stage_id, id).

- FK `maturity_reassessment_events_ibfk_3`: (actor_id, organization_id) → `users` (id, organization_id).

- CHECK `maturity_reassessment_events_chk_1`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `maturity_reassessment_events_chk_2`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `maturity_reassessment_events_chk_3`: `(`event` in (_utf8mb4\'opened\',_utf8mb4\'saved\',_utf8mb4\'changes_requested\',_utf8mb4\'approved\',_utf8mb4\'access_denied\'))`.

- CHECK `maturity_reassessment_events_chk_4`: `(json_type(`details_json`) = _utf8mb4\'OBJECT\')`.

## maturity_reassessment_operation_assignments

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `grantee_id` | `bigint unsigned` | NO | `` |   |
| `issuer_id` | `bigint unsigned` | NO | `` |   |
| `operation` | `varchar(24)` | NO | `` |   |
| `starts_at` | `datetime` | NO | `` |   |
| `expires_at` | `datetime` | NO | `` |   |
| `revoked_at` | `datetime` | YES | `` |   |
| `revoked_by` | `bigint unsigned` | YES | `` |   |

الفهارس: `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id); `ra_grant_grantee` INDEX (grantee_id, organization_id); `ra_grant_identity` UNIQUE (organization_id, fixture_id, id, grantee_id); `ra_grant_issuer` INDEX (issuer_id, organization_id); `ra_grant_once` UNIQUE (organization_id, fixture_id, approach_id, stage_id, grantee_id, operation); `ra_grant_revoker` INDEX (revoked_by, organization_id); `ra_grant_tenant` UNIQUE (organization_id, id).

- FK `ra_grant_grantee`: (grantee_id, organization_id) → `users` (id, organization_id).

- FK `ra_grant_issuer`: (issuer_id, organization_id) → `users` (id, organization_id).

- FK `ra_grant_org`: (organization_id) → `organizations` (id).

- FK `ra_grant_revoker`: (revoked_by, organization_id) → `users` (id, organization_id).

- FK `ra_grant_stage`: (organization_id, fixture_id, approach_id, stage_id) → `maturity_workflow_stages` (organization_id, fixture_id, approach_id, id).

- CHECK `ra_grant_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `ra_grant_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `ra_grant_operation`: `(`operation` in (_utf8mb4\'open_reassessment\',_utf8mb4\'save_reassessment\',_utf8mb4\'review_reassessment\',_utf8mb4\'history_reassessment\'))`.

- CHECK `ra_grant_period`: `(((`starts_at` + interval 24 hour) is not null) and (`expires_at` > `starts_at`) and (`expires_at` = (`starts_at` + interval 24 hour)))`.

- CHECK `ra_grant_revocation`: `(((`revoked_at` is null) and (`revoked_by` is null)) or ((`revoked_at` is not null) and (`revoked_by` is not null) and (`revoked_at` >= `starts_at`)))`.

- CHECK `ra_grant_uint`: `((`organization_id` > 0) and (`approach_id` > 0) and (`stage_id` > 0) and (`grantee_id` > 0) and (`issuer_id` > 0) and ((`revoked_by` is null) or (`revoked_by` > 0)))`.

## maturity_reassessment_operation_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `assignment_id` | `bigint unsigned` | YES | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `subject_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(24)` | NO | `` |   |
| `event_key` | `varchar(100)` | NO | `` |   |
| `details_json` | `json` | NO | `` |   |

الفهارس: `PRIMARY` UNIQUE (id); `public_id` UNIQUE (public_id); `ra_change_actor` INDEX (actor_id, organization_id); `ra_change_event` UNIQUE (organization_id, fixture_id, event_key); `ra_change_grant` INDEX (organization_id, fixture_id, assignment_id, subject_id); `ra_change_subject` INDEX (subject_id, organization_id); `ra_change_tenant` UNIQUE (organization_id, id).

- FK `ra_change_actor`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `ra_change_grant`: (organization_id, fixture_id, assignment_id, subject_id) → `maturity_reassessment_operation_assignments` (organization_id, fixture_id, id, grantee_id).

- FK `ra_change_org`: (organization_id) → `organizations` (id).

- FK `ra_change_subject`: (subject_id, organization_id) → `users` (id, organization_id).

- CHECK `ra_change_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `ra_change_details`: `(json_type(`details_json`) = _utf8mb4\'OBJECT\')`.

- CHECK `ra_change_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `ra_change_kind`: `(`event` in (_utf8mb4\'granted\',_utf8mb4\'revoked\',_utf8mb4\'expiration_observed\',_utf8mb4\'access_denied\'))`.

- CHECK `ra_change_reference`: `((`assignment_id` is not null) or (`event` = _utf8mb4\'access_denied\'))`.

- CHECK `ra_change_uint`: `((`organization_id` > 0) and (`actor_id` > 0) and (`subject_id` > 0) and ((`assignment_id` is null) or (`assignment_id` > 0)))`.

## maturity_workflow_assessments

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `stage_ordinal` | `tinyint unsigned` | NO | `` |   |
| `submission_id` | `bigint unsigned` | NO | `` |   |
| `review_id` | `bigint unsigned` | NO | `` |   |
| `reviewer_id` | `bigint unsigned` | NO | `` |   |
| `previous_assessment_id` | `bigint unsigned` | YES | `` |   |
| `previous_ordinal` | `tinyint unsigned` | YES | `` | STORED GENERATED generated: `(case when (`stage_ordinal` > 1) then (`stage_ordinal` - 1) else NULL end)` |
| `approved_state` | `varchar(20)` | YES | `` | STORED GENERATED generated: `_utf8mb4\'approved\'` |
| `selected_band_index` | `int unsigned` | NO | `` |   |
| `snapshot_json` | `json` | NO | `` |   |
| `reason` | `longtext` | NO | `` |   |
| `notes` | `longtext` | YES | `` |   |

الفهارس: `mw_assess_approach` INDEX (organization_id, approach_id); `mw_assess_first_only` UNIQUE (organization_id, fixture_id, approach_id, stage_id); `mw_assess_previous_fk` INDEX (organization_id, fixture_id, approach_id, previous_assessment_id, previous_ordinal); `mw_assess_previous_identity` UNIQUE (organization_id, fixture_id, approach_id, id, stage_ordinal); `mw_assess_public` UNIQUE (public_id); `mw_assess_reassessment_identity` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id); `mw_assess_review_fk` INDEX (organization_id, fixture_id, approach_id, stage_id, review_id, submission_id, reviewer_id, approved_state); `mw_assess_reviewer_id_fk` INDEX (reviewer_id, organization_id); `mw_assess_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `mw_assess_stage_fk` INDEX (organization_id, fixture_id, approach_id, stage_id, stage_ordinal); `mw_assess_tenant` UNIQUE (organization_id, id); `PRIMARY` UNIQUE (id).

- FK `mw_assess_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `mw_assess_org`: (organization_id) → `organizations` (id).

- FK `mw_assess_previous_fk`: (organization_id, fixture_id, approach_id, previous_assessment_id, previous_ordinal) → `maturity_workflow_assessments` (organization_id, fixture_id, approach_id, id, stage_ordinal).

- FK `mw_assess_review_fk`: (organization_id, fixture_id, approach_id, stage_id, review_id, submission_id, reviewer_id, approved_state) → `review_maturity_reviews` (organization_id, fixture_id, approach_id, stage_id, id, submission_id, reviewer_id, state).

- FK `mw_assess_reviewer_id_fk`: (reviewer_id, organization_id) → `users` (id, organization_id).

- FK `mw_assess_stage_fk`: (organization_id, fixture_id, approach_id, stage_id, stage_ordinal) → `maturity_workflow_stages` (organization_id, fixture_id, approach_id, id, stage_ordinal).

- CHECK `mw_assess_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `mw_assess_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_ascii\'[^0-9a-f]\'))))`.

- CHECK `mw_assess_json`: `((json_type(`snapshot_json`) = _utf8mb4\'OBJECT\') and json_contains_path(`snapshot_json`,_utf8mb4\'all\',_utf8mb4\'$.scale\',_utf8mb4\'$.selected_band_index\',_utf8mb4\'$.answers\') and (json_type(json_extract(`snapshot_json`,_utf8mb4\'$.scale\')) = _utf8mb4\'ARRAY\') and (json_type(json_extract(`snapshot_json`,_utf8mb4\'$.selected_band_index\')) = _utf8mb4\'INTEGER\') and (`selected_band_index` < json_length(json_extract(`snapshot_json`,_utf8mb4\'$.scale\'))) and (json_extract(`snapshot_json`,_utf8mb4\'$.selected_band_index\') = `selected_band_index`))`.

- CHECK `mw_assess_reason`: `(char_length(trim(`reason`)) > 0)`.

- CHECK `mw_assess_sequence`: `(((`stage_ordinal` = 1) and (`previous_assessment_id` is null)) or ((`stage_ordinal` between 2 and 5) and (`previous_assessment_id` is not null)))`.

## maturity_workflow_configurations

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `version_number` | `int unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `configuration_json` | `json` | NO | `` |   |
| `fingerprint` | `char(64)` | NO | `` |   |

الفهارس: `mw_config_actor_id_fk` INDEX (actor_id, organization_id); `mw_config_approach` INDEX (organization_id, approach_id); `mw_config_identity` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id); `mw_config_public` UNIQUE (public_id); `mw_config_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `mw_config_tenant` UNIQUE (organization_id, id); `mw_config_version` UNIQUE (organization_id, fixture_id, approach_id, stage_id, version_number); `PRIMARY` UNIQUE (id).

- FK `mw_config_actor_id_fk`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `mw_config_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `mw_config_org`: (organization_id) → `organizations` (id).

- FK `mw_config_stage_fk`: (organization_id, fixture_id, approach_id, stage_id) → `maturity_workflow_stages` (organization_id, fixture_id, approach_id, id).

- CHECK `mw_config_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `mw_config_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `mw_config_hash`: `regexp_like(`fingerprint`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\')`.

- CHECK `mw_config_json`: `((json_type(`configuration_json`) = _utf8mb4\'OBJECT\') and json_contains_path(`configuration_json`,_utf8mb4\'all\',_utf8mb4\'$.questions\',_utf8mb4\'$.scale\') and (json_type(json_extract(`configuration_json`,_utf8mb4\'$.questions\')) = _utf8mb4\'ARRAY\') and (json_type(json_extract(`configuration_json`,_utf8mb4\'$.scale\')) = _utf8mb4\'ARRAY\') and (json_length(json_extract(`configuration_json`,_utf8mb4\'$.scale\')) > 0))`.

- CHECK `mw_config_number`: `((`version_number` > 0) and (`version_number` <= 4294967295))`.

## maturity_workflow_evidence

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `submission_id` | `bigint unsigned` | NO | `` |   |
| `question_public_id` | `char(26)` | NO | `` |   |
| `expected_evidence_public_id` | `char(26)` | NO | `` |   |
| `document_id` | `bigint unsigned` | NO | `` |   |
| `document_version_id` | `bigint unsigned` | NO | `` |   |

الفهارس: `mw_evidence_approach` INDEX (organization_id, approach_id); `mw_evidence_history_idx` INDEX (organization_id, fixture_id, approach_id, stage_id, submission_id); `mw_evidence_once` UNIQUE (organization_id, fixture_id, approach_id, submission_id, question_public_id, expected_evidence_public_id, document_version_id); `mw_evidence_public` UNIQUE (public_id); `mw_evidence_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `mw_evidence_tenant` UNIQUE (organization_id, id); `mw_evidence_version_fk` INDEX (organization_id, document_id, document_version_id); `PRIMARY` UNIQUE (id).

- FK `mw_evidence_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `mw_evidence_org`: (organization_id) → `organizations` (id).

- FK `mw_evidence_submission_fk`: (organization_id, fixture_id, approach_id, stage_id, submission_id) → `maturity_workflow_submissions` (organization_id, fixture_id, approach_id, stage_id, id).

- FK `mw_evidence_version_fk`: (organization_id, document_id, document_version_id) → `document_versions` (organization_id, document_id, id).

- CHECK `mw_evidence_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `mw_evidence_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

## maturity_workflow_stages

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `stage` | `varchar(20)` | NO | `` |   |
| `stage_ordinal` | `tinyint unsigned` | NO | `` |   |

الفهارس: `mw_stage_approach` INDEX (organization_id, approach_id); `mw_stage_once` UNIQUE (organization_id, fixture_id, approach_id, stage); `mw_stage_ordinal` UNIQUE (organization_id, fixture_id, approach_id, id, stage_ordinal); `mw_stage_public` UNIQUE (public_id); `mw_stage_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `mw_stage_tenant` UNIQUE (organization_id, id); `PRIMARY` UNIQUE (id).

- FK `mw_stage_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `mw_stage_org`: (organization_id) → `organizations` (id).

- CHECK `mw_stage_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `mw_stage_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `mw_stage_kind`: `(`stage` in (_utf8mb4\'orientation\',_utf8mb4\'system\',_utf8mb4\'implementation\',_utf8mb4\'measurement\',_utf8mb4\'improvement\'))`.

- CHECK `mw_stage_order`: `(`stage_ordinal` = (case `stage` when _utf8mb4\'orientation\' then 1 when _utf8mb4\'system\' then 2 when _utf8mb4\'implementation\' then 3 when _utf8mb4\'measurement\' then 4 when _utf8mb4\'improvement\' then 5 end))`.

## maturity_workflow_submissions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `stage_id` | `bigint unsigned` | NO | `` |   |
| `configuration_id` | `bigint unsigned` | NO | `` |   |
| `version_number` | `int unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `content_json` | `json` | NO | `` |   |
| `fingerprint` | `char(64)` | NO | `` |   |

الفهارس: `mw_sub_actor_id_fk` INDEX (actor_id, organization_id); `mw_sub_approach` INDEX (organization_id, approach_id); `mw_sub_config_fk` INDEX (organization_id, fixture_id, approach_id, stage_id, configuration_id); `mw_sub_identity` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id); `mw_sub_public` UNIQUE (public_id); `mw_sub_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `mw_sub_tenant` UNIQUE (organization_id, id); `mw_sub_version` UNIQUE (organization_id, fixture_id, approach_id, stage_id, version_number); `PRIMARY` UNIQUE (id).

- FK `mw_sub_actor_id_fk`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `mw_sub_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `mw_sub_config_fk`: (organization_id, fixture_id, approach_id, stage_id, configuration_id) → `maturity_workflow_configurations` (organization_id, fixture_id, approach_id, stage_id, id).

- FK `mw_sub_org`: (organization_id) → `organizations` (id).

- CHECK `mw_sub_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `mw_sub_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `mw_sub_hash`: `regexp_like(`fingerprint`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\')`.

- CHECK `mw_sub_json`: `((json_type(`content_json`) = _utf8mb4\'OBJECT\') and json_contains_path(`content_json`,_utf8mb4\'all\',_utf8mb4\'$.answers\',_utf8mb4\'$.evidence\',_utf8mb4\'$.configuration\') and (json_type(json_extract(`content_json`,_utf8mb4\'$.answers\')) = _utf8mb4\'ARRAY\') and (json_type(json_extract(`content_json`,_utf8mb4\'$.evidence\')) = _utf8mb4\'ARRAY\') and (json_type(json_extract(`content_json`,_utf8mb4\'$.configuration\')) = _utf8mb4\'OBJECT\'))`.

- CHECK `mw_sub_number`: `((`version_number` > 0) and (`version_number` <= 4294967295))`.

## migrations

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `int unsigned` | NO | `` | auto_increment  |
| `migration` | `varchar(255)` | NO | `` |   |
| `batch` | `int` | NO | `` |   |

الفهارس: `PRIMARY` UNIQUE (id).

## model_has_permissions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `permission_id` | `bigint unsigned` | NO | `` |   |
| `model_type` | `varchar(100)` | NO | `` |   |
| `model_id` | `bigint unsigned` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |

الفهارس: `direct_permissions_membership_fk` INDEX (model_id, organization_id); `direct_permissions_permission_fk` INDEX (permission_id); `PRIMARY` UNIQUE (organization_id, permission_id, model_id, model_type).

- FK `direct_permissions_membership_fk`: (model_id, organization_id) → `organization_memberships` (user_id, organization_id).

- FK `direct_permissions_permission_fk`: (permission_id) → `permissions` (id).

## model_has_roles

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `role_id` | `bigint unsigned` | NO | `` |   |
| `model_type` | `varchar(100)` | NO | `` |   |
| `model_id` | `bigint unsigned` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |

الفهارس: `assigned_roles_membership_fk` INDEX (model_id, organization_id); `PRIMARY` UNIQUE (organization_id, role_id, model_id, model_type).

- FK `assigned_roles_membership_fk`: (model_id, organization_id) → `organization_memberships` (user_id, organization_id).

- FK `assigned_roles_scope_fk`: (organization_id, role_id) → `roles` (organization_id, id).

## organization_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(20)` | NO | `` |   |
| `previous_name` | `varchar(255)` | YES | `` |   |
| `new_name` | `varchar(255)` | NO | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `organization_changes_actor_fk` INDEX (actor_id); `organization_changes_organization_fk` INDEX (organization_id); `PRIMARY` UNIQUE (id).

- FK `organization_changes_actor_fk`: (actor_id) → `users` (id).

- FK `organization_changes_organization_fk`: (organization_id) → `organizations` (id).

- CHECK `organization_changes_event_check`: `(`event` in (_utf8mb4\'created\',_utf8mb4\'renamed\'))`.

## organization_memberships

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `user_id` | `bigint unsigned` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `created_at` | `timestamp` | YES | `` |   |
| `updated_at` | `timestamp` | YES | `` |   |

الفهارس: `memberships_user_organization_unique` UNIQUE (user_id, organization_id); `organization_memberships_organization_id_index` INDEX (organization_id); `PRIMARY` UNIQUE (id).

- FK `organization_memberships_organization_id_foreign`: (organization_id) → `organizations` (id).

- FK `organization_memberships_user_id_foreign`: (user_id) → `users` (id).

- Trigger `identity_membership_insert`: BEFORE INSERT؛ التعريف الكامل في ترحيل الجدول، واختبار TriggerMigrationSafetyTest.

- Trigger `identity_membership_update`: BEFORE UPDATE؛ التعريف الكامل في ترحيل الجدول، واختبار TriggerMigrationSafetyTest.

## organizational_unit_changes

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `unit_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(20)` | NO | `` |   |
| `previous_parent_id` | `bigint unsigned` | YES | `` |   |
| `new_parent_id` | `bigint unsigned` | YES | `` |   |
| `previous_name` | `varchar(255)` | YES | `` |   |
| `new_name` | `varchar(255)` | YES | `` |   |
| `created_at` | `timestamp` | NO | `` |   |

الفهارس: `PRIMARY` UNIQUE (id); `unit_changes_actor_fk` INDEX (actor_id); `unit_changes_new_parent_fk` INDEX (organization_id, new_parent_id); `unit_changes_previous_parent_fk` INDEX (organization_id, previous_parent_id); `unit_changes_unit_fk` INDEX (organization_id, unit_id).

- FK `unit_changes_actor_fk`: (actor_id) → `users` (id).

- FK `unit_changes_new_parent_fk`: (organization_id, new_parent_id) → `organizational_units` (organization_id, id).

- FK `unit_changes_organization_fk`: (organization_id) → `organizations` (id).

- FK `unit_changes_previous_parent_fk`: (organization_id, previous_parent_id) → `organizational_units` (organization_id, id).

- FK `unit_changes_unit_fk`: (organization_id, unit_id) → `organizational_units` (organization_id, id).

- CHECK `unit_changes_event_check`: `(`event` in (_utf8mb4\'created\',_utf8mb4\'renamed\',_utf8mb4\'reparented\'))`.

## organizational_units

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `parent_id` | `bigint unsigned` | YES | `` |   |
| `name` | `varchar(255)` | NO | `` |   |
| `created_at` | `timestamp` | YES | `` |   |
| `updated_at` | `timestamp` | YES | `` |   |

الفهارس: `organizational_units_public_id_unique` UNIQUE (public_id); `PRIMARY` UNIQUE (id); `units_organization_id_unique` UNIQUE (organization_id, id); `units_organization_parent_index` INDEX (organization_id, parent_id).

- FK `organizational_units_organization_id_foreign`: (organization_id) → `organizations` (id).

- FK `units_same_organization_parent_fk`: (organization_id, parent_id) → `organizational_units` (organization_id, id).

- Trigger `units_no_self_parent_insert`: BEFORE INSERT؛ التعريف الكامل في ترحيل الجدول، واختبار TriggerMigrationSafetyTest.

- Trigger `units_no_self_parent_update`: BEFORE UPDATE؛ التعريف الكامل في ترحيل الجدول، واختبار TriggerMigrationSafetyTest.

## organizations

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `name` | `varchar(255)` | NO | `` |   |
| `created_at` | `timestamp` | YES | `` |   |
| `updated_at` | `timestamp` | YES | `` |   |

الفهارس: `organizations_public_id_unique` UNIQUE (public_id); `PRIMARY` UNIQUE (id).

## password_reset_tokens

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `email` | `varchar(255)` | NO | `` |   |
| `token` | `varchar(255)` | NO | `` |   |
| `created_at` | `timestamp` | YES | `` |   |

الفهارس: `PRIMARY` UNIQUE (email).

## permissions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `name` | `varchar(125)` | NO | `` |   |
| `guard_name` | `varchar(30)` | NO | `` |   |
| `created_at` | `timestamp` | YES | `` |   |
| `updated_at` | `timestamp` | YES | `` |   |

الفهارس: `permissions_name_guard_unique` UNIQUE (name, guard_name); `PRIMARY` UNIQUE (id).

## review_maturity_assignments

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `grantee_id` | `bigint unsigned` | NO | `` |   |
| `issuer_id` | `bigint unsigned` | NO | `` |   |
| `stage_id` | `bigint unsigned` | YES | `` |   |
| `operation` | `varchar(24)` | NO | `` |   |
| `starts_at` | `datetime` | NO | `` |   |
| `expires_at` | `datetime` | NO | `` |   |
| `revoked_at` | `datetime` | YES | `` |   |
| `revoked_by` | `bigint unsigned` | YES | `` |   |
| `scope_key` | `bigint unsigned` | YES | `` | STORED GENERATED generated: `coalesce(`stage_id`,0)` |

الفهارس: `PRIMARY` UNIQUE (id); `rm_grant_approach` INDEX (organization_id, approach_id); `rm_grant_current_idx` INDEX (organization_id, fixture_id, grantee_id, operation, approach_id, stage_id); `rm_grant_grantee_id_fk` INDEX (grantee_id, organization_id); `rm_grant_identity` UNIQUE (organization_id, fixture_id, approach_id, id, grantee_id, scope_key, operation); `rm_grant_issuer_id_fk` INDEX (issuer_id, organization_id); `rm_grant_once` UNIQUE (organization_id, fixture_id, approach_id, grantee_id, operation, scope_key); `rm_grant_public` UNIQUE (public_id); `rm_grant_revoked_by_fk` INDEX (revoked_by, organization_id); `rm_grant_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `rm_grant_stage_fk` INDEX (organization_id, fixture_id, approach_id, stage_id); `rm_grant_tenant` UNIQUE (organization_id, id).

- FK `rm_grant_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `rm_grant_grantee_id_fk`: (grantee_id, organization_id) → `users` (id, organization_id).

- FK `rm_grant_issuer_id_fk`: (issuer_id, organization_id) → `users` (id, organization_id).

- FK `rm_grant_org`: (organization_id) → `organizations` (id).

- FK `rm_grant_revoked_by_fk`: (revoked_by, organization_id) → `users` (id, organization_id).

- FK `rm_grant_stage_fk`: (organization_id, fixture_id, approach_id, stage_id) → `maturity_workflow_stages` (organization_id, fixture_id, approach_id, id).

- CHECK `rm_grant_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `rm_grant_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `rm_grant_operation`: `(((`stage_id` is null) and (`operation` in (_utf8mb4\'read_workflow\',_utf8mb4\'edit_content\',_utf8mb4\'review_content\'))) or ((`stage_id` is not null) and (`operation` in (_utf8mb4\'configure_stage\',_utf8mb4\'answer_stage\',_utf8mb4\'review_stage\'))))`.

- CHECK `rm_grant_period`: `(((`starts_at` + interval 24 hour) is not null) and (`expires_at` > `starts_at`) and (`expires_at` = (`starts_at` + interval 24 hour)))`.

- CHECK `rm_grant_revoked`: `(((`revoked_at` is null) and (`revoked_by` is null)) or ((`revoked_at` is not null) and (`revoked_by` is not null) and (`revoked_at` >= `starts_at`)))`.

## review_maturity_audit

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `actor_id` | `bigint unsigned` | NO | `` |   |
| `subject_id` | `bigint unsigned` | YES | `` |   |
| `stage_id` | `bigint unsigned` | YES | `` |   |
| `assignment_id` | `bigint unsigned` | YES | `` |   |
| `review_id` | `bigint unsigned` | YES | `` |   |
| `operation` | `varchar(24)` | YES | `` |   |
| `event` | `varchar(28)` | NO | `` |   |
| `event_key` | `varchar(100)` | NO | `` |   |
| `scope_key` | `bigint unsigned` | YES | `` | STORED GENERATED generated: `coalesce(`stage_id`,0)` |

الفهارس: `PRIMARY` UNIQUE (id); `rm_audit_actor_id_fk` INDEX (actor_id, organization_id); `rm_audit_approach` INDEX (organization_id, approach_id); `rm_audit_event` UNIQUE (organization_id, fixture_id, event_key); `rm_audit_grant_fk` INDEX (organization_id, fixture_id, approach_id, assignment_id, subject_id, scope_key, operation); `rm_audit_history_idx` INDEX (organization_id, fixture_id, approach_id, id); `rm_audit_public` UNIQUE (public_id); `rm_audit_review_fk` INDEX (organization_id, fixture_id, approach_id, scope_key, review_id); `rm_audit_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `rm_audit_stage_fk` INDEX (organization_id, fixture_id, approach_id, stage_id); `rm_audit_subject_id_fk` INDEX (subject_id, organization_id); `rm_audit_tenant` UNIQUE (organization_id, id).

- FK `rm_audit_actor_id_fk`: (actor_id, organization_id) → `users` (id, organization_id).

- FK `rm_audit_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `rm_audit_grant_fk`: (organization_id, fixture_id, approach_id, assignment_id, subject_id, scope_key, operation) → `review_maturity_assignments` (organization_id, fixture_id, approach_id, id, grantee_id, scope_key, operation).

- FK `rm_audit_org`: (organization_id) → `organizations` (id).

- FK `rm_audit_review_fk`: (organization_id, fixture_id, approach_id, scope_key, review_id) → `review_maturity_reviews` (organization_id, fixture_id, approach_id, scope_key, id).

- FK `rm_audit_stage_fk`: (organization_id, fixture_id, approach_id, stage_id) → `maturity_workflow_stages` (organization_id, fixture_id, approach_id, id).

- FK `rm_audit_subject_id_fk`: (subject_id, organization_id) → `users` (id, organization_id).

- CHECK `rm_audit_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `rm_audit_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `rm_audit_operation_scope`: `((`operation` is null) or ((`stage_id` is null) and (`operation` in (_utf8mb4\'read_workflow\',_utf8mb4\'edit_content\',_utf8mb4\'review_content\'))) or ((`stage_id` is not null) and (`operation` in (_utf8mb4\'configure_stage\',_utf8mb4\'answer_stage\',_utf8mb4\'review_stage\'))))`.

- CHECK `rm_audit_reference`: `(((`event` in (_utf8mb4\'granted\',_utf8mb4\'revoked\',_utf8mb4\'expiration_observed\',_utf8mb4\'access_denied\')) and (`subject_id` is not null) and (`review_id` is null) and (`operation` is not null) and (`operation` in (_utf8mb4\'read_workflow\',_utf8mb4\'edit_content\',_utf8mb4\'review_content\',_utf8mb4\'configure_stage\',_utf8mb4\'answer_stage\',_utf8mb4\'review_stage\')) and ((`assignment_id` is not null) or (`event` = _utf8mb4\'access_denied\'))) or ((`event` in (_utf8mb4\'review_opened\',_utf8mb4\'changes_requested\',_utf8mb4\'review_approved\',_utf8mb4\'review_superseded\',_utf8mb4\'assessment_recorded\')) and (`review_id` is not null) and (`assignment_id` is null) and (`subject_id` is null) and (`operation` is null)))`.

## review_maturity_notifications

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `recipient_id` | `bigint unsigned` | NO | `` |   |
| `stage_id` | `bigint unsigned` | YES | `` |   |
| `review_id` | `bigint unsigned` | NO | `` |   |
| `event` | `varchar(24)` | NO | `` |   |
| `event_key` | `char(64)` | NO | `` |   |
| `title` | `varchar(255)` | NO | `` |   |
| `reason` | `varchar(255)` | NO | `` |   |
| `read_at` | `datetime` | YES | `` |   |
| `scope_key` | `bigint unsigned` | YES | `` | STORED GENERATED generated: `coalesce(`stage_id`,0)` |

الفهارس: `PRIMARY` UNIQUE (id); `rm_notice_approach` INDEX (organization_id, approach_id); `rm_notice_inbox_idx` INDEX (organization_id, fixture_id, recipient_id, created_at, id); `rm_notice_once` UNIQUE (organization_id, fixture_id, recipient_id, event_key); `rm_notice_public` UNIQUE (public_id); `rm_notice_recipient_id_fk` INDEX (recipient_id, organization_id); `rm_notice_review_fk` INDEX (organization_id, fixture_id, approach_id, scope_key, review_id); `rm_notice_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `rm_notice_tenant` UNIQUE (organization_id, id).

- FK `rm_notice_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `rm_notice_org`: (organization_id) → `organizations` (id).

- FK `rm_notice_recipient_id_fk`: (recipient_id, organization_id) → `users` (id, organization_id).

- FK `rm_notice_review_fk`: (organization_id, fixture_id, approach_id, scope_key, review_id) → `review_maturity_reviews` (organization_id, fixture_id, approach_id, scope_key, id).

- CHECK `rm_notice_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `rm_notice_event`: `(`event` in (_utf8mb4\'review_opened\',_utf8mb4\'changes_requested\',_utf8mb4\'approved\'))`.

- CHECK `rm_notice_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `rm_notice_key`: `regexp_like(`event_key`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\')`.

- CHECK `rm_notice_read`: `((`read_at` is null) or (`read_at` >= `created_at`))`.

- CHECK `rm_notice_text`: `((char_length(trim(`title`)) > 0) and (char_length(trim(`reason`)) > 0))`.

## review_maturity_reviews

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `public_id` | `char(26)` | NO | `` |   |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `fixture_id` | `char(32)` | NO | `` |   |
| `approach_id` | `bigint unsigned` | NO | `` |   |
| `scenario` | `varchar(40)` | NO | `REVIEW_MATURITY_LOCAL_001` |   |
| `classification` | `varchar(16)` | NO | `TEST_ONLY` |   |
| `created_at` | `datetime` | NO | `` |   |
| `stage_id` | `bigint unsigned` | YES | `` |   |
| `target_kind` | `varchar(24)` | NO | `` |   |
| `approach_revision_id` | `bigint unsigned` | YES | `` |   |
| `submission_id` | `bigint unsigned` | YES | `` |   |
| `review_number` | `int unsigned` | NO | `` |   |
| `fingerprint` | `char(64)` | NO | `` |   |
| `opened_by` | `bigint unsigned` | NO | `` |   |
| `request_key` | `char(26)` | NO | `` |   |
| `state` | `varchar(20)` | NO | `` |   |
| `reviewer_id` | `bigint unsigned` | YES | `` |   |
| `decision_assignment_id` | `bigint unsigned` | YES | `` |   |
| `decision_key` | `char(26)` | YES | `` |   |
| `decision_fingerprint` | `char(64)` | YES | `` |   |
| `decided_at` | `datetime` | YES | `` |   |
| `reason` | `longtext` | YES | `` |   |
| `supersedes_id` | `bigint unsigned` | YES | `` |   |
| `previous_review_number` | `int unsigned` | YES | `` | STORED GENERATED generated: `(case when (`review_number` > 1) then (`review_number` - 1) else NULL end)` |
| `scope_key` | `bigint unsigned` | YES | `` | STORED GENERATED generated: `coalesce(`stage_id`,0)` |
| `active_slot` | `tinyint unsigned` | YES | `` | STORED GENERATED generated: `(case when (`state` = _utf8mb4\'pending\') then 1 else NULL end)` |
| `decision_operation` | `varchar(24)` | YES | `` | STORED GENERATED generated: `(case when (`target_kind` = _utf8mb4\'approach_content\') then _utf8mb4\'review_content\' else _utf8mb4\'review_stage\' end)` |

الفهارس: `PRIMARY` UNIQUE (id); `rm_review_active` UNIQUE (organization_id, fixture_id, approach_id, target_kind, scope_key, active_slot); `rm_review_approved_identity` UNIQUE (organization_id, fixture_id, approach_id, stage_id, id, submission_id, reviewer_id, state); `rm_review_decision_request` UNIQUE (organization_id, fixture_id, approach_id, decision_key); `rm_review_grant_fk` INDEX (organization_id, fixture_id, approach_id, decision_assignment_id, reviewer_id, scope_key, decision_operation); `rm_review_inbox_idx` INDEX (organization_id, fixture_id, state, approach_id, stage_id, created_at, id); `rm_review_number` UNIQUE (organization_id, fixture_id, approach_id, target_kind, scope_key, review_number); `rm_review_opened_by_fk` INDEX (opened_by, organization_id); `rm_review_previous_identity` UNIQUE (organization_id, fixture_id, approach_id, scope_key, id, review_number); `rm_review_public` UNIQUE (public_id); `rm_review_request` UNIQUE (organization_id, fixture_id, approach_id, request_key); `rm_review_reviewer_id_fk` INDEX (reviewer_id, organization_id); `rm_review_revision_fk` INDEX (organization_id, approach_id, approach_revision_id); `rm_review_scope` UNIQUE (organization_id, fixture_id, approach_id, id); `rm_review_scope_identity` UNIQUE (organization_id, fixture_id, approach_id, scope_key, id); `rm_review_submission_fk` INDEX (organization_id, fixture_id, approach_id, stage_id, submission_id); `rm_review_supersedes_fk` INDEX (organization_id, fixture_id, approach_id, scope_key, supersedes_id, previous_review_number); `rm_review_tenant` UNIQUE (organization_id, id).

- FK `rm_review_approach`: (organization_id, approach_id) → `approaches` (organization_id, id).

- FK `rm_review_grant_fk`: (organization_id, fixture_id, approach_id, decision_assignment_id, reviewer_id, scope_key, decision_operation) → `review_maturity_assignments` (organization_id, fixture_id, approach_id, id, grantee_id, scope_key, operation).

- FK `rm_review_opened_by_fk`: (opened_by, organization_id) → `users` (id, organization_id).

- FK `rm_review_org`: (organization_id) → `organizations` (id).

- FK `rm_review_reviewer_id_fk`: (reviewer_id, organization_id) → `users` (id, organization_id).

- FK `rm_review_revision_fk`: (organization_id, approach_id, approach_revision_id) → `approach_content_revisions` (organization_id, approach_id, id).

- FK `rm_review_stage_fk`: (organization_id, fixture_id, approach_id, stage_id) → `maturity_workflow_stages` (organization_id, fixture_id, approach_id, id).

- FK `rm_review_submission_fk`: (organization_id, fixture_id, approach_id, stage_id, submission_id) → `maturity_workflow_submissions` (organization_id, fixture_id, approach_id, stage_id, id).

- FK `rm_review_supersedes_fk`: (organization_id, fixture_id, approach_id, scope_key, supersedes_id, previous_review_number) → `review_maturity_reviews` (organization_id, fixture_id, approach_id, scope_key, id, review_number).

- CHECK `rm_review_chain`: `(((`review_number` = 1) and (`supersedes_id` is null)) or ((`review_number` > 1) and (`supersedes_id` is not null)))`.

- CHECK `rm_review_class`: `((`classification` = _utf8mb4\'TEST_ONLY\') and (`scenario` = _utf8mb4\'REVIEW_MATURITY_LOCAL_001\'))`.

- CHECK `rm_review_decision_hash`: `((`decision_fingerprint` is null) or regexp_like(`decision_fingerprint`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\'))`.

- CHECK `rm_review_fixture`: `((char_length(`fixture_id`) = 32) and (not(regexp_like(`fixture_id`,_utf8mb4\'[^0-9a-f]\'))))`.

- CHECK `rm_review_hash`: `regexp_like(`fingerprint`,_utf8mb4\'^[0-9a-f]{64}$\',_utf8mb4\'c\')`.

- CHECK `rm_review_target`: `(((`target_kind` = _utf8mb4\'approach_content\') and (`stage_id` is null) and (`approach_revision_id` is not null) and (`submission_id` is null)) or ((`target_kind` = _utf8mb4\'maturity_submission\') and (`stage_id` is not null) and (`approach_revision_id` is null) and (`submission_id` is not null)))`.

- CHECK `rm_review_time`: `((`decided_at` is null) or (`decided_at` >= `created_at`))`.

- CHECK `rm_review_transition`: `(((`state` = _utf8mb4\'pending\') and (`reviewer_id` is null) and (`decided_at` is null) and (`decision_assignment_id` is null) and (`decision_key` is null) and (`decision_fingerprint` is null) and (`reason` is null)) or ((`state` = _utf8mb4\'superseded\') and (`reviewer_id` is null) and (`decided_at` is not null) and (`decision_assignment_id` is null) and (`decision_key` is null) and (`decision_fingerprint` is null) and (`reason` is null)) or ((`state` in (_utf8mb4\'approved\',_utf8mb4\'changes_requested\')) and (`reviewer_id` is not null) and (`decided_at` is not null) and (`decision_assignment_id` is not null) and (`decision_key` is not null) and (`decision_fingerprint` is not null) and ((`state` = _utf8mb4\'approved\') or ((`reason` is not null) and (char_length(trim(`reason`)) > 0)))))`.

- CHECK `rm_review_version`: `((`review_number` > 0) and (`review_number` <= 4294967295))`.

## role_has_permissions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `permission_id` | `bigint unsigned` | NO | `` |   |
| `role_id` | `bigint unsigned` | NO | `` |   |

الفهارس: `PRIMARY` UNIQUE (permission_id, role_id); `role_permissions_role_fk` INDEX (role_id).

- FK `role_permissions_permission_fk`: (permission_id) → `permissions` (id).

- FK `role_permissions_role_fk`: (role_id) → `roles` (id).

## roles

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `organization_id` | `bigint unsigned` | NO | `` |   |
| `name` | `varchar(125)` | NO | `` |   |
| `guard_name` | `varchar(30)` | NO | `` |   |
| `created_at` | `timestamp` | YES | `` |   |
| `updated_at` | `timestamp` | YES | `` |   |

الفهارس: `PRIMARY` UNIQUE (id); `roles_organization_id_unique` UNIQUE (organization_id, id); `roles_organization_name_guard_unique` UNIQUE (organization_id, name, guard_name).

- FK `roles_organization_fk`: (organization_id) → `organizations` (id).

## sessions

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `varchar(255)` | NO | `` |   |
| `user_id` | `bigint unsigned` | YES | `` |   |
| `ip_address` | `varchar(45)` | YES | `` |   |
| `user_agent` | `text` | YES | `` |   |
| `payload` | `longtext` | NO | `` |   |
| `last_activity` | `int` | NO | `` |   |

الفهارس: `PRIMARY` UNIQUE (id); `sessions_last_activity_index` INDEX (last_activity); `sessions_user_id_index` INDEX (user_id).

## users

| العمود | النوع | NULL | الافتراضي | خصائص إضافية |
|---|---|---|---|---|
| `id` | `bigint unsigned` | NO | `` | auto_increment  |
| `name` | `varchar(255)` | NO | `` |   |
| `email` | `varchar(255)` | NO | `` |   |
| `email_verified_at` | `timestamp` | YES | `` |   |
| `password` | `varchar(255)` | NO | `` |   |
| `remember_token` | `varchar(100)` | YES | `` |   |
| `created_at` | `timestamp` | YES | `` |   |
| `updated_at` | `timestamp` | YES | `` |   |
| `organization_id` | `bigint unsigned` | YES | `` |   |
| `public_id` | `char(26)` | YES | `` |   |
| `principal_type` | `varchar(16)` | NO | `legacy` |   |
| `identity_namespace` | `varchar(48)` | YES | `` | VIRTUAL GENERATED generated: `(case when (`principal_type` = _utf8mb4\'institution\') then concat(_utf8mb4\'institution:\',`organization_id`) else `principal_type` end)` |

الفهارس: `PRIMARY` UNIQUE (id); `users_id_organization_unique` UNIQUE (id, organization_id); `users_identity_organization_fk` INDEX (organization_id); `users_namespace_email_unique` UNIQUE (identity_namespace, email); `users_public_id_unique` UNIQUE (public_id).

- FK `users_identity_organization_fk`: (organization_id) → `organizations` (id).

- CHECK `users_identity_email_check`: `((`principal_type` = _utf8mb4\'legacy\') or ((length(`email`) = char_length(`email`)) and (cast(`email` as char charset binary) = cast(lower(`email`) as char charset binary))))`.

- CHECK `users_identity_shape_check`: `(((`principal_type` = _utf8mb4\'legacy\') and (`organization_id` is null) and (`public_id` is null)) or ((`principal_type` = _utf8mb4\'platform\') and (`organization_id` is null) and (`public_id` is not null)) or ((`principal_type` = _utf8mb4\'institution\') and (`organization_id` is not null) and (`public_id` is not null)))`.

- Trigger `identity_binding_update`: BEFORE UPDATE؛ التعريف الكامل في ترحيل الجدول، واختبار TriggerMigrationSafetyTest.

## جرد الترحيلات المثبت

- [0001_01_01_000000_create_users_table.php](../../database/migrations/0001_01_01_000000_create_users_table.php)
- [0001_01_01_000001_create_cache_table.php](../../database/migrations/0001_01_01_000001_create_cache_table.php)
- [0001_01_01_000002_create_jobs_table.php](../../database/migrations/0001_01_01_000002_create_jobs_table.php)
- [2026_10_31_000001_create_organizations_table.php](../../database/migrations/2026_10_31_000001_create_organizations_table.php)
- [2026_10_31_000002_create_organization_memberships_table.php](../../database/migrations/2026_10_31_000002_create_organization_memberships_table.php)
- [2026_10_31_000003_create_organizational_units_table.php](../../database/migrations/2026_10_31_000003_create_organizational_units_table.php)
- [2026_11_03_000001_create_permission_permissions_table.php](../../database/migrations/2026_11_03_000001_create_permission_permissions_table.php)
- [2026_11_03_000002_create_permission_roles_table.php](../../database/migrations/2026_11_03_000002_create_permission_roles_table.php)
- [2026_11_03_000003_create_permission_role_has_permissions_table.php](../../database/migrations/2026_11_03_000003_create_permission_role_has_permissions_table.php)
- [2026_11_03_000004_create_permission_model_has_roles_table.php](../../database/migrations/2026_11_03_000004_create_permission_model_has_roles_table.php)
- [2026_11_03_000005_create_permission_model_has_permissions_table.php](../../database/migrations/2026_11_03_000005_create_permission_model_has_permissions_table.php)
- [2026_11_03_000006_create_permission_authorization_changes_table.php](../../database/migrations/2026_11_03_000006_create_permission_authorization_changes_table.php)
- [2026_11_03_000007_create_organizational_unit_changes_table.php](../../database/migrations/2026_11_03_000007_create_organizational_unit_changes_table.php)
- [2026_11_03_000008_create_organization_changes_table.php](../../database/migrations/2026_11_03_000008_create_organization_changes_table.php)
- [2026_11_05_000001_expand_user_identity_namespaces.php](../../database/migrations/2026_11_05_000001_expand_user_identity_namespaces.php)
- [2026_11_05_000002_guard_identity_membership_insert.php](../../database/migrations/2026_11_05_000002_guard_identity_membership_insert.php)
- [2026_11_05_000003_guard_identity_membership_update.php](../../database/migrations/2026_11_05_000003_guard_identity_membership_update.php)
- [2026_11_05_000004_guard_identity_binding_update.php](../../database/migrations/2026_11_05_000004_guard_identity_binding_update.php)
- [2026_11_05_000005_create_institution_password_reset_tokens.php](../../database/migrations/2026_11_05_000005_create_institution_password_reset_tokens.php)
- [2026_11_07_000001_create_approaches_table.php](../../database/migrations/2026_11_07_000001_create_approaches_table.php)
- [2026_11_07_000002_create_approach_content_revisions_table.php](../../database/migrations/2026_11_07_000002_create_approach_content_revisions_table.php)
- [2026_11_08_000001_create_approach_changes_table.php](../../database/migrations/2026_11_08_000001_create_approach_changes_table.php)
- [2026_11_08_000002_create_documents_table.php](../../database/migrations/2026_11_08_000002_create_documents_table.php)
- [2026_11_08_000003_create_document_versions_table.php](../../database/migrations/2026_11_08_000003_create_document_versions_table.php)
- [2026_11_10_000001_create_approach_assignments_table.php](../../database/migrations/2026_11_10_000001_create_approach_assignments_table.php)
- [2026_11_10_000002_create_approach_assignment_changes_table.php](../../database/migrations/2026_11_10_000002_create_approach_assignment_changes_table.php)
- [2026_11_11_000001_create_document_workspace_assignments_table.php](../../database/migrations/2026_11_11_000001_create_document_workspace_assignments_table.php)
- [2026_11_11_000002_create_document_workspace_assignment_changes_table.php](../../database/migrations/2026_11_11_000002_create_document_workspace_assignment_changes_table.php)
- [2026_11_11_000003_create_document_metadata_revisions_table.php](../../database/migrations/2026_11_11_000003_create_document_metadata_revisions_table.php)
- [2026_11_12_000001_add_approach_revision_workflow_candidate_key.php](../../database/migrations/2026_11_12_000001_add_approach_revision_workflow_candidate_key.php)
- [2026_11_12_000002_add_document_version_workflow_candidate_key.php](../../database/migrations/2026_11_12_000002_add_document_version_workflow_candidate_key.php)
- [2026_11_12_000003_create_maturity_workflow_stages_table.php](../../database/migrations/2026_11_12_000003_create_maturity_workflow_stages_table.php)
- [2026_11_12_000004_create_review_maturity_assignments_table.php](../../database/migrations/2026_11_12_000004_create_review_maturity_assignments_table.php)
- [2026_11_12_000005_create_maturity_workflow_configurations_table.php](../../database/migrations/2026_11_12_000005_create_maturity_workflow_configurations_table.php)
- [2026_11_12_000006_create_maturity_workflow_submissions_table.php](../../database/migrations/2026_11_12_000006_create_maturity_workflow_submissions_table.php)
- [2026_11_12_000007_create_maturity_workflow_evidence_table.php](../../database/migrations/2026_11_12_000007_create_maturity_workflow_evidence_table.php)
- [2026_11_12_000008_create_review_maturity_reviews_table.php](../../database/migrations/2026_11_12_000008_create_review_maturity_reviews_table.php)
- [2026_11_12_000009_create_maturity_workflow_assessments_table.php](../../database/migrations/2026_11_12_000009_create_maturity_workflow_assessments_table.php)
- [2026_11_12_000010_create_review_maturity_audit_table.php](../../database/migrations/2026_11_12_000010_create_review_maturity_audit_table.php)
- [2026_11_12_000011_create_review_maturity_notifications_table.php](../../database/migrations/2026_11_12_000011_create_review_maturity_notifications_table.php)
- [2026_11_13_000001_add_reassessment_parent_key.php](../../database/migrations/2026_11_13_000001_add_reassessment_parent_key.php)
- [2026_11_13_000002_create_document_intakes_table.php](../../database/migrations/2026_11_13_000002_create_document_intakes_table.php)
- [2026_11_13_000003_create_document_intake_events_table.php](../../database/migrations/2026_11_13_000003_create_document_intake_events_table.php)
- [2026_11_13_000004_create_maturity_reassessment_attempts_table.php](../../database/migrations/2026_11_13_000004_create_maturity_reassessment_attempts_table.php)
- [2026_11_13_000005_create_maturity_reassessment_decisions_table.php](../../database/migrations/2026_11_13_000005_create_maturity_reassessment_decisions_table.php)
- [2026_11_13_000006_create_maturity_reassessment_events_table.php](../../database/migrations/2026_11_13_000006_create_maturity_reassessment_events_table.php)
- [2026_11_14_000001_add_document_public_scope_identity.php](../../database/migrations/2026_11_14_000001_add_document_public_scope_identity.php)
- [2026_11_14_000002_create_document_file_operation_assignments_table.php](../../database/migrations/2026_11_14_000002_create_document_file_operation_assignments_table.php)
- [2026_11_14_000003_create_document_file_operation_changes_table.php](../../database/migrations/2026_11_14_000003_create_document_file_operation_changes_table.php)
- [2026_11_14_000004_create_maturity_reassessment_operation_assignments_table.php](../../database/migrations/2026_11_14_000004_create_maturity_reassessment_operation_assignments_table.php)
- [2026_11_14_000005_create_maturity_reassessment_operation_changes_table.php](../../database/migrations/2026_11_14_000005_create_maturity_reassessment_operation_changes_table.php)
- [2026_11_14_000006_create_document_demo_availability_decisions_table.php](../../database/migrations/2026_11_14_000006_create_document_demo_availability_decisions_table.php)

فشل DDL على MySQL قد يسبق كتابة journal؛ تُراجع البنية والصفوف والسجل يدويًا، دون اعتماد تلقائي لجدول موجود أو إسقاطه. ترحيلات persistence/closure محروسة ببيئة testing وهدف disposable؛ ليست حزمة ترقية مأذونة لقاعدة التطوير. [التشغيل الآمن](../operations/LARAVEL-HERD-WINDOWS-RUNBOOK-AR.md) يفصل ذلك.
