# خطة Phase08 — Issue42: Document/Version وFilament غير مفعّل

2026-11-08. الفرع الموجود feat/42-documents-filament-foundation، الأساس/main f51dcc0f03344052a3e5560ced77e0f4091de674، protected=true. CI42420613277 ناجح على هذاSHA؛ decoded logs232SQLite/2656assertions،167MySQL/1410،4Redis/19:403/4085. tree يطابق PR41 النهائي؛ لا إعادة فتح38/40 أو لمس37. worktree الحالي اسمه تاريخي issue-38-approaches، لكن الفرع42 ونظيف؛ محاولة worktree جديد توقفت لأن الفرع موجود بالفعل، ولم يُبدل عمل آخر.

## السلطة والبوابات

SRS v3.0 والمصادر الخاصة راجعت؛ بصمة/revision في التسليم الخاص فقط. ADR001/002/003 ACCEPTED وADR004 PROPOSED، ledger وfoundation وtenant authorization/schema وapproach bank والمصدر والCI/conventions الحالية مراجعة. FR-DOC002/003 أساس فصل الهوية/الإصدار؛001 جزئي لأن owner غير محسوم ولا intake مستخدم؛006 قراءة داخلية فقط؛004/005/012/013 حماية امتداد مستقبلي دون link/status؛007..010 و014 PROPOSED و011 OPEN. الإجابات المستلمة عن الحالات والملفات ليست تفويضًا لتجاوز حدود العقد أو خدمة AV غير موجودة. لا تعديل SRS/ledger/ADRs، لا اقتباس خاص فيGit.

G2/D002، D006، D007، D008/011/028، D013/022، D027/030 وIssue13 BLOCKED operational. A داخلي فقط، B PREPARED/TESTED/NOT OPERATIONAL. لا مالک=creator، لا states/approval/EvidenceLink/FileUpload/Download/URL/permission/Policy/Panel routes.

## المخطط المحدد قبل الكود

ملفا migration الاسمان متاحان بعد22ملفًا؛ الإضافة المتوقعة24 يجب إثباتها DB لا رفع أعمى للعدد:
- 2026_11_08_000002_create_documents_table.php
- 2026_11_08_000003_create_document_versions_table.php

documents: id BIGINT UNSIGNED، public_id CHAR26 unique immutable، organization_id FK root RESTRICT، title VARCHAR255، description LONGTEXT، created_by FKusers RESTRICT، created_at فقط. UNIQUE(org,id)، INDEX(org,created_at,id). لا مالك/حالات/softdelete/updated_at أو uniqueness عنوان مخترعة.
document_versions: id/public_id، organization_id/document_id مركبان RESTRICT إلىdocuments(org,id) وrootFK، version_number INT UNSIGNED CHECK1..4294967295 وUNIQUE(doc,version)، actor FKusers RESTRICT، storage_disk fixedlocal، storage_key VARCHAR255 ASCII-safe generated unique، sha256 CHAR64 lowercasehex CHECK، byte_size BIGINT UNSIGNED nonnegativeCHECK، original_filename VARCHAR255 displayonly، detected_media_type nullableVARCHAR255 تقني غير مفعل، created_at فقط.
CHECK disk='local'؛ keys/hash/types لا تمنح file policy أو dedup. Blobkey docs-path بمؤسسة/وثيقة/إصدار ULIDs وعشوائية مستقلة لاfilename. لا morph/cross-domain FK.

~~~mermaid
erDiagram
 organizations ||--o{ documents : scopes
 users ||--o{ documents : creates
 documents ||--o{ document_versions : retains
 organizations ||--o{ document_versions : scopes
 users ||--o{ document_versions : authors
~~~

MySQL CREATE واحد بكل قيود كل جدول/الفهرس، SQLitetransactionلtable/index. newestdown يرفض قبلFIRSTDROP مع أيDocument/Version حتىidentityonly؛ emptyrollback/reapply فقط. لا تعديلoldmigration/globalserver. crashبعدDDLقبلjournal يحتاجoperator inspection، لا adopt/drop تلقائيًا.

## byte-write والتعويض والأقفال

CreateDocumentWithVersion يملك narrow Documents-only withinContext (لا إساءة استخدامCreateApproach): current locks org→actor→membership؛ يعيد استعمالTenantContext لا إطارauthorizationجديد. legacy الداخلي متوافق معboundaryالحالي، platformمرفوض، institutionbindingحاضر. ليسgrant لأعضاءالمؤسسة. الكتابة local/testing فقط، رفضouterDBtransaction قبلأيbytewrite؛ read callbacks يمكنnestedلcheck currentmembership.

Internalinput resource لphp://memory أوphp://temp synthetic streams، لاUploadedFile أوpath/stringبديل، arrayallowlisttitle/description/original_filename فقطللإنشاء وoriginal_filenameللappend. Unicode/length/nonblank داخلAction، لاquota/MIMEallowlist.
Preflightlockedmembership/resourceثمrelease، generateULIDs/key، localprivateblobwrite/readbackSHA256/size خارجDBlocks، ثمrevalidatecurrentmembership/resource داخلtransaction وتخصيصn+1بcurrentlatestlock وUNIQUE. FileI/OليسdistributedtransactionولاnetworkI/Oداخلالأقفال.

PrivateDocumentBlobStore: disklocal فقط، يعاينlocaladapter/privatephysicalroot ويرفضpublic/symlinkexposure؛ Storagefakeمحلي مستقل. حجزحصري fopen x للkeyالجديد قبلStorageput يمنعoverwrite/تَبني ملفسابق؛ cleanupattempt-ownedkeyفقط. putfalse/readbackmismatch/missingblob أخطاءصريحة. verify streaming لايعيدbytesلUI. identicalbytesnewversion مستقلة ولاdedup/no-op مفروضة.

CompleteWrite callback يتعامل معDBfailure بعدwrite: افحصversionpublicidلتجنبحذفblobقديكونcommittedعندcommitambiguity؛ absent→attemptcleanup، exists/unknown→retain/reportrecovery ولاfalse success. cleanupfailureلايخفيoriginalerror. processcrashقديتركorphan؛ reconciler/retentionمهمةلاحقة. no broaddeleteDirectory، لاdeletion/purgeAPIللنسخالمحفوظة.

## classes والملفات المبررة

A:
- app/Models/Document.php وDocumentVersion.php: guarded/hiddeninternalstorage/ordinaryupdate-deleteblocked وعلاقاتscoped.
- app/Actions/Documents/CreateDocumentWithVersion.php: boundary/preflight/validation/create+v1/completewritecompensation، لاgenericlayer.
- AppendDocumentVersion.php: lockexistingdoc/currentnextversion، reuseboundary/persistversion.
- ListDocuments.php: boundedscopedsearchsort/count/MAXsubqueries(noN+1)، publicarraysexcludehash/filename/actorcontacts/storage.
- ReadDocumentHistory.php: scopedboundedasc/descversionhistory وexplicitinternalintegrityverification؛ noHTTPbytes.
- app/Services/Documents/PrivateDocumentBlobStore.php: byteboundary/reservation/hashreadback/attemptcleanup فقط.
- migrations بالمسارينأعلاه.
- tests/Feature/Documents/DocumentManagementTest.php وDocumentStorageSafetyTest.php: nativepositive/negative/outerreject/faults/retries/scopes/files.
- tests/Integration/MySql/DocumentSchemaIsolationTest.php وDocumentVersionConcurrencyTest.php: schema/DML/DDL/currentread/twoprocessconcurrency. harness/traitsداخلallowlistedtestfiles، لاhelperpathجديد.

B:
- app/Filament/Documents/Blueprints/DocumentTableBlueprint.php: realTablecustomrecords منreadservicecallback، nativeTextColumns/search/sort/pagination، noquery/write/actions.
- DocumentDetailsBlueprint.php: Schema/Section/TextEntryلmetadata/latestnumber؛ noowner/creator/hashdisplayغيرمخول.
- DocumentVersionsBlueprint.php: nativeTablehistorydescending؛ nofilename/hash/storage fields/default.
- DocumentFormBlueprint.php: nativeTextInput/Textarea disabled/dehydratedfalse وText/disabledAction؛ neverFileUpload.
- resources/views/filament/documents/document-shell.blade.php وupload-disabled.blade.php: shellunregisteredلnativecomponents، qep-preview/tokens وRTL، truthfuldisabled/forbidden/error/empty، لاواجهةHTMLبديلة.
- lang/ar/institution_documents.php: Arabiclabels/messages.
- tests/Feature/Filament/DocumentFilamentPresentationTest.php: in-fileLivewireharness/test-onlyfixtures، nativeAPI/render/serialization/rejectmutations/routeproviderimmutability/375..1280visual.
لاCSSجديدلعدموجودمسارCSSمسموح؛ existingqep/nativeclassesكافية. Theme/provider/previews/Resources/Pages/routesreadonly. Filamentالمثبت5.9.xحسبlock يتمتأكيدexactversionبالاختبارات، لاافتراضAPIv3/4.

Docs:
- docs/architecture/document-version-foundation.md: exactcontracts/tradeoffs/blockedgates.
- هذاplanأولcommit.
- docs/engineering/reports/42.md: 46ACmatrix/actualresults/fileinventory/risks/branchPR.
- .local-tools/reports/phase-08/42/{private-handover,schema-and-storage,test-evidence,file-inventory}.md بعدcheck-ignore، privateevidenceلاGit.

## الثمانية المستثناة وحدود إضافية

مصرحفقطtests/Support/TenantIsolationSchemaContract.php، docs/architecture/tenant-isolation-schema-contract.md، وMySQL{TenantIntegrityTest,TriggerMigrationSafetyTest,InstitutionIdentityMigrationTest,HierarchyHistoryIntegrityTest,OrganizationHistoryIntegrityTest,PermissionIntegrityTest}.php.
الأولانclassification/FK/type/check، Tenantcountmeasured، Triggerdependencyordering، identityoriginal5pathsremainingcount، historyselectedremovalcount، Permissionstepsretainoriginaltarget. حفظكلnegatives/scenarios/assertions.

**NEEDS LEAD SCOPE AMENDMENT المتوقع:** ApproachSchemaIsolationTest.php خارجالثمانية؛ يحتويliteral22counts وrelative rollbacksteps ستتأثر بترحيلينجديدين. لنأعدلهأوالتَف علىاختباراته. بعدإثباتالفشلactualMySQLسأطلبإذنامحددالهذاالمسارفقط(الأعداد/استهدافالترحيلالأصلي)، وأواصلA/Bالمستقلين. لاإذنمفتوحأوتغييرCIلإخفاءالفشل.

## التحقق والتسليم

معايير01..46 matrixلكلnamedtest وPASS/BLOCKED/NOTRUNحقيقي. FocusedثمfullSQLite/MySQL8.4/Redis، Composerstrict/install/platform/audit، Pinttest، npmci/build، migrationstatus/emptyrollback/reapply، cachesوHTTP، actualfinalHEADCI. Snapshot قبلالتعديلroute/schema/contracts، readonlydiff وallowlist وprivateignoreverified. لا baselinetestskip/إضعافلضماناته؛ failuresdocumentfix.

DraftPRواحدعلىالفرعنفسه، finalpublicreportcommitثمCIعلىHEADالمحتويله وAPIhead_sha/decodedlogsverified. immutablefinalreceiptفيPR/privateلتجنبselfhashloop. No merge/deploy/selfapprove/privilegeactivation.
