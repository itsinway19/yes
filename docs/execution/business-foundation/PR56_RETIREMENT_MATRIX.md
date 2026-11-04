# PR56 — مصفوفة تقاعد Workspace التجاري

مرجع المقارنة: `881091bfd1b33dca86e8c6d7187c0d7ded6a8268`؛ التنفيذ على شجرة العمل المحلية اللاحقة غير الملتزم بها. هذا سجل تحقق، وليس تفويضًا لحذف البيانات أو افتراض مطابقة بين Workspace وBusiness.

الحالة الحالية: **LOCAL_GATES_VERIFIED / HOSTED_CI_PENDING**. التشغيل الشامل الثاني اجتاز1307اختباراتPHP/19214تحققًا، ثم توقف عند اختبارJavaScript قديم يعتمد حقلGoals متقاعدًا في جرد توثيقي. صُحح الاختبار ليفحص المسارات المسجلة فعليًا؛ واجتاز الاختيار الكامل344اختبارJavaScript، وAPI وPint الشامل والبناء وحارس الأصول. ملفاتPHP/المنتج/الاعتمادات لم تتغير، لذا أعيد استخدام أدلتها دون تكرار التشغيل الطويل. المحاولات الفاشلة تبقى فاشلة، وFoundation المستضاف على مرشحPR الفعلي ما زال مطلوبًا. [سجل النشر المصرح](../structural-foundation/IMPLEMENTATION_PROGRESS.md#pr56-authorized-publication-and-hosted-acceptance--2026-11-04) يحدد النتائج وحدودها. لا تفويض دمج أو نشر إنتاجي.

## حسم المسؤوليات ومعيار القبول

Business يملك الحقائق التجارية الحالية: Context والأسواق والنية والتفضيلات وتصريحات أغراض النشاط. Workspace هو التمثيل التشغيلي القائم للجهة وعضويتها ومالكها المسؤول ومواردها؛ ليس Business للعميل ولا يوجد Organization موازٍ. تسجيل الوكالة/الشركة عبر `/organizations/new` ينشئ هذه الجهة مباشرة دون تأسيس حاوية ثانية أو استكمال ملف تجاري قديم. التسجيل لا يثبت الهوية القانونية أو وكالة خارجية عن عميل.

القبول المحلي يتطلب: غياب الكاتب القديم من كتالوج SQL المستهدف، رفض HTTP حتى مع العضوية/المنحة القديمة، حواجز آثار تمنع binary قديمًا من الكتابة، بقاء عضوية/اتصالات/مصادر الجهة دون legacy admission، تاريخ وأصول وحقوق محفوظة، ورحلات Business والوكالة ذات مُصدر تشغيلي. انخفاض عدد الملفات/الاختبارات ليس سببًا للاختيار أو دليلًا عليه.

## قرار Purpose المعتمد وحدوده

**OWNER_APPROVED / AMEND_AND_ACCEPT_AS_AMENDED — 2026-11-04.** منشئ Business الأصلي فقط، بهويته الحالية وسند `self_declared_draft` الأصلي المؤهل، يؤكد صراحة بعد مصادقة حديثة لتفعيل حق واحد `business.purpose.declare` وفق `business_purpose_statement_v1`. الحق مباشر غير قابل للتفويض؛ لا يحصل عليه موظف أو وكالة أو أمين ملف داخلي تلقائيًا. السياسة تستخدم التأهيل والمنح والإيصالات وK4 والمحرك القائم، دون محرك حقوق موازٍ.

التصريح يعبّر عن غرض النشاط. لا يثبت ملكية أي عنصر خارجي ولا يجيز اتصال المصدر أو قراءة بياناته أو استخدامها أو تعديل/نشر إعلان أو تمثيل عميل. حق القراءة الأصلي يظل مستقلًا، وقراءة تصريح/إقرار كتابته لا تمنح حق استعمال مصدره. السحب لا يمحو UUID أو الإصدارات أو التصحيحات؛ إعادة التأهيل تصدر منحة جديدة بعد مراجعة المنحة السابقة ولا تحيي القديمة.

الأغراض الأصلية ليست كلها تصريحات Business قابلة للنقل. حفظ تصحيح/سحب غرض موجود ذي أصل Workspace مسؤولية أصلية مستقلة: صاحب المسؤولية الأصلي الحالي، المصادقة الحديثة، هوية الغرض وإصداره وسنده القديم. لا إنشاء أو replace في هذا المسار، ولا نقل تلقائي أو ادعاء ملكية عناصر خارجية. بعد cutover مؤهل يُقرأ الأصل عبر Business legacy-history وحقوقه المستقلة؛ لا يعاد فتح رأس Workspace.

## مصفوفة المداخل والعقود

جميع مداخل HTTP أدناه يملكها `routes/web.php`؛ المسارات المتقاعدة تعيد410/no-store من `RetiredWorkspaceCommercialController` بعد حراسة الهوية القائمة.

| المدخل/العقد | التصرف | المسؤولية النهائية والدليل المطلوب |
|---|---|---|
| `/dashboard` | نقل | مجموعة Business؛ لا تحويل Workspace إلى نشاط ضمني. |
| GET `/business/start`؛ POST نفس المسار | تقاعد | GET إلى تسجيل الجهة؛ POST410 بلا حقائق/جهة جديدة. |
| GET `/organizations/new`؛ POST `/organizations` | نقل | تأسيس الجهة الطبيعي عبر WorkspaceAuthority الذري، دون Business type/context/goals. |
| GET/POST `/workspaces` | احتفاظ مبرر | اختيار/إنشاء المورد التنظيمي القائم فقط؛ كاتب الإنشاء لا يصدر منحة تجارية. |
| GET `/workspaces/{workspace}` و`/foundation` | نقل | metadata ومصادر الجهة عبر organization_workspaces؛ لا profile أو legacy enter. |
| GET/POST `/api/organizations/{organization}/team` | نقل | عضوية الجهة؛ إدارة مالكها ومغادرة العضو لنفسه، حتى دون workspace.view. |
| POST `.../reassess-owner-grant` وgrant/service/recovery routes | احتفاظ مبرر | الوظائف التنظيمية الأصلية فقط؛ لا إعادة إحياء منحة تجارية متقاعدة. |
| GET/POST `/workspaces/{workspace}/business` | تقاعد |410؛ حُذف Controller والمحرر؛ Business Context هو الكاتب الوحيد. |
| POST `.../business/authority` | تقاعد |410؛ issuer القديم والمنحة الأولية أزيلا؛ التاريخ لا يحذف. |
| POST `.../business/target-markets` و`.../business/current-intent` | تقاعد |410؛ Business facts يملك الأسواق والنية والتأكيدات والإصدارات. |
| GET `.../goals` و`.../goals/review` و`.../goals/context`؛ POST `.../goals` | تقاعد |410؛ لا Goals writer/editor بديل. الكتالوج والـnormalizer مشتركان فقط. |
| GET `.../object-purposes[/context]` و`.../object-purposes/{purpose}[/context]`؛ POST المجموعة و`/{purpose}/revisions` | تقاعد |410؛ الكاتب native القديم محذوف من SQL. |
| GET `.../commercial-history` | تاريخ ضروري | رؤوس/إصدارات أصلية read-only، pagination50، RLS/visibility witness/current view، لا inferred Business. |
| GET `.../purpose-history[/context]` و`/{purpose}[/context]`؛ POST `/{purpose}/revisions` | تاريخ ضروري | original-owner qualification؛ existing correction/withdrawal فقط، لا declare/replace، exact permits للمحرك المشترك. |
| POST `/api/businesses` ومجموعة/list/open/read/surface | احتفاظ مبرر | تأسيس وقراءة Business مستقل دون Workspace. |
| PUT `/api/businesses/{business}/{context,markets,intent,preferences}` | نقل | كتّاب Business وإيصالات/version/qualification الحالية. |
| POST `/api/businesses/{business}/purpose-enrollment`؛ GET/POST `/purposes` | نقل | المُصدر المحدود المعتمد؛ محرك Purpose الحالي وإصداراته، statement-only contract ورفض التفويض/المصادر. |
| POST `/api/businesses/{business}/direct-team` و`/internal-team` و`/grants` | نقل | تفعيل صريح للسياسات المحدودة القائمة؛5أفعال تجارية فقط للموظف، لا Purpose/source/data/private-history ضمنًا. |
| `/workspace-links` | احتفاظ مبرر | علاقة مؤهلة؛ اسم/عضوية الجهة لا يصدران حقًا على العميل. |
| GET `/api/businesses/{business}/sources` | نقل | جرد إداري `manageable_sources` و`establishes_business_data_use=false`. |
| `/data-use-bindings` و`/api/data-rights` ونطاقات الاستعمال | احتفاظ مبرر | تأهيل منفصل exact source/resource/operation/purpose/fields/realm/client-boundary؛ الربط والإدارة لا يثبتان استمرار حق الاستعمال. |
| `/source-rights` و`/api/source-rights` وواجهات المصادر الأصلية | احتفاظ مبرر | إدارة المورد وحقوقه الأصلية، source adoption mutex وcurrent principal/service authority باقية. |
| Meta/Google/TikTok/Snapchat/X connect/select/discovery/callback/report/jobs | نقل | metadata تنظيمية لا Business Context أو legacy entry؛ ملاحظات المزود لا تصرّح بسوق أو نية أو غرض. |
| `/api/businesses/{business}/history` و`/legacy-history` | تاريخ ضروري | نسخة Business الحالية/الأصل المؤهل فقط؛ private history ليس حق عضوية أو حق فريق تلقائيًا. |

## SQL وRLS والترقية

| العنصر | التصرف | السبب/الضمان |
|---|---|---|
| migrations570/580؛ direct_team/organization_team | نقل | تأسيس الفريق وعضوية الجهة بإصدارات join مستقلة؛ departure/rejoin لا يحييان حقوق موظف قديمة. |
| migration590؛ organization_resources.sql | نقل | view أمنية ذات أعمدة metadata محدودة؛ signed current principal/member/workspace.view أو service الأصلي، لا حق عميل. |
| migration600؛ commercial_retirement.sql | تقاعد | DROP أربعة كتّاب facts + prepare/require_prepared + issuer القديم/hook genesis؛ terminal INSERT/UPDATE/DELETE guards على الرؤوس/الأحداث والمنحة التجارية. السحب الأحادي التاريخي فقط جائز. |
| legacy_workspace_commercial_names؛ retired_legacy_inventory.sql | تاريخ ضروري | لقطة اسم الأصل immutable قبل استقلال تغيير اسم الجهة؛ qualified origin precedence/digest محفوظان ولا يعاد تفسير السجلات. |
| migration610؛ purpose_retirement.sql | نقل/تاريخ | DROP الكاتب native؛ apply/guard_effect ثابتان معلنان، جميع آثار Purpose بإذن دقيق؛ history wrapper لا يستدعي الكاتب القديم أو يمنح generic permit للمستخدم. |
| migration620؛ purpose_statement_policy.sql؛ scope_authority/surfaces | نقل | السياسة المعتمدة ضمن السلسلة القائمة؛ original basis داخل sorted K4 قبل القفل، وفحص حديث عند الأثر/الإيصال، لا توسيع lock footprint متأخر. |
| workspaces.business_type_id/business_context_version/current_intent | تاريخ ضروري | رؤوس مجمدة؛ إنشاء الجهة يسمح فقط NULL/0/NULL. name تنظيمي قابل للتغيير بعد حفظ الاسم التجاري الأصلي. |
| business_context_events؛ business_target_markets/events؛ business_current_intent_events؛ advertiser_goal_preferences/events | تاريخ ضروري | لا كتابة تشغيلية بعد600 حتى من SECURITY DEFINER قديم؛ تبقى الهوية/الإصدارات/حقوق القراءة الأصلية. |
| object_purposes/revisions/command_receipts/business_context_refs | احتفاظ/تاريخ | مالك واحد لكل استدعاء؛ Workspace أو Business صريح، لا تحويل origin أو UUID. المحرك مشترك والحقوق منفصلة. |
| legacy_workspace_profile_retired/legacy_profile_retired وnative event/Purpose RLS | احتفاظ مبرر | raw commercial rows تظل خاضعة لسند الأصل/cohort؛ projection الجهة لا يعيد فتحها. |
| legacy_fences/cohort/import/seals/mappings | احتفاظ مبرر/تاريخ | التنسيق/الأصل المؤهل ليس كاتب حقائق؛ LEGACY_ACTIVE لا يتجاوز terminal guards. birth يحتفظ بالتنسيق القائم فقط، لا منحة تجارية ولا اعتمادية للمصادر عليه. |
| old SQL installation files/migrations السابقة | تاريخ ضروري | مطلوبة لترقية القواعد القديمة وبناء synthetic pre-retirement fixtures؛ ليست المخطط التشغيلي النهائي بعد600/610. لا تحرير للتاريخ لإخفاء الكاتب السابق. |
| normalization/catalog/countries/timezones/Purpose projection | احتفاظ مبرر | shared semantics الفعلية لدى Business، لا store/authority موازٍ. Nested timezone-support EXECUTE لـscope_executor صحح اعتماد INVOKER القائم دون منح حق تجاري أو مصدر. |
| legacy snapshot/history rights/mapping/import | احتفاظ مبرر | لا ترحيل اعتمادًا على الاسم/العضوية/نشاط مفترض؛ production inventory/manifest/qualifications قرار منفصل. لم يجر أي تطبيق على preview/production. |

## نقل الاختبارات وحواجز CI

| الاختبارات/الdrivers المتقاعدة | السلوك المستمر ومالكه الجديد |
|---|---|
| BusinessContextLockOrderTest؛ GoalsSpaIntegration/ProofTest؛ AdvertiserGoalPreferencesTest | BusinessFoundationContract/BusinessFactFinalAuthority/BusinessPreferenceVariants؛ WorkspaceCommercialRetirement/LegacyCohortFence للرفض؛ history admission في WorkspaceSpaIntegration. |
| DeclaredTargetMarkets{Test,Database,Security,Concurrency} | BusinessFact/FactFinalAuthority وBusinessPurposeDtmLineage/Authority؛ الحفاظ على no-op/expected-context/material-DTM/lineage/denial، لا افتراض اشتراك المورد. |
| CurrentIntent{Test,Database,Security,Concurrency} | CurrentIntentNormalization؛ BusinessIntentOptions؛ BusinessFactFinalAuthority؛ confirmations/whole-business scope/actual timezone catalog/support-loss/calendar/technical-shape محفوظة. |
| ObjectPurpose{Test,Http,Security,Concurrency,DtmLineage} | BusinessPurposeTemporal/Authority/DtmLineage/Test/FactFinalAuthority/StatementPolicy؛ LegacyPurposeHistory يحتفظ بحق الأصل/upgrade/correction/withdrawal، لا new-native positives. |
| old Context/Goals/Markets/Intent JS editor suites | advertiser-workspace compiled actual BusinessFacts/retained drafts/explicit confirmations؛ application-api-client exact resource/action; مشتركات ObjectPurpose interaction باقية. |
| old Goals/Context/Purpose browser positive drivers | business-surface-browser actual operating facts/team/Purpose/lostACK/revocation؛ workspace-retirement-browser HTTP410؛ focused-spa-acceptance/goals-spa-restoration/closure على history والهوية الحالية. لا SSE تجاري تجريبي متقاعد. |
| legacy manual durability/UI scripts وold command/lock workers | تقاعد | أهلية الترقية تُختبر بـOrganizationResourcesUpgrade/LegacyPurposeHistory/BusinessQualifiedImport، والانتظار الفعلي لدى BusinessFactFinalAuthority. لا driver تشغيلي يستدعي writer محذوفًا. |
| OrganizationTeam/DirectTeam/Registration |6رحلات المالك: معلن بلا Workspace؛ تسجيل وكالة؛3عملاء بلا حسابات؛ تكليف محدود؛ نفس Business؛ departure/rejoin دون خسارة الأصل المؤهل. |
| source registry/BusinessSourceVisibility/provider geography/jobs | فصل الإدارة عن الاستخدام؛ draining لا يغلق الاتصال؛ provider observation لا يكتب declarations. |
| tests/workspace-commercial-retirement.test.mjs؛ package.json؛ foundation.yml | CI فعلي: المسار داخل npm test؛ يمنع رجوع المداخل/المحررات/المتطلبات التجارية للمصادر، يثبت SQL DROP والعقود الإدارية. Feature tests ضمن PHP الرسمي تمنع الآثار عبر API/SQL وتثبت policy/upgrade/isolation. |

الاختبارات المصطنعة تثبت العقد، ولا تثبت صحة أغراض عميل حقيقي أو حقوق مزود أو outcome تجاري. إعادة استخدام مُصدر مؤهل مصطنع في اختبار variation لا يعوّض مُصدرًا تشغيليًا؛ اختبارات السياسة والمتصفح تستعمل المُصدر الحقيقي صراحة.

## حدود التسليم

لا مانع بيانات إنتاجية يبرر إعادة تفعيل الكاتب القديم. الأصول تبقى في تاريخها حتى ترحيل مؤهل مستقل؛ عدم اختراع Mapping ليس منعًا لتقاعد التشغيل. أي Purpose قديم يحتاج سلطة خارجية مختلفة يبقى ضمن تاريخ أصله وحقوقه؛ لا يُروّج إلى سياسة own-statement.

الأدلة المركزة اكتملت؛ السجل أدناه يميز النتائج الناجحة والمحاولات المرفوضة ولا يجمع الحالات المتكررة باعتبارها تغطية إضافية. المرشح المحلي مجمد بملف candidate-manifest.json وبصمته في دليل التسليم المحلي. Foundation/hosted CI والقبول النهائي **NOT_RUN/PENDING_OWNER** لهذا المرشح، لا نجاحًا مفترضًا. لا commit/push/merge/deploy أو ترحيل بيانات دائمة نُفّذ في هذا التصحيح.

## أدلة الإغلاق المحلية

- `21 PASS /938 assertions` في `storage/app/pr56-correction-20261104/final-affected-tests.xml`، ومعه log/source-manifest؛ source-unchanged=True. تشمل المصدر التشغيلي لـPurpose وإعادة التأهيل، upgrade populated وحماية اسم الأصل، رفض HTTP/SQL/الآثار، provenance المرجع القطري، عزل السوق، وOAuth/queues للمزودين الخمسة. لا أخطاء/إخفاقات/تخطي.
- إعادة اختبارات مصادر الجهة وإعداد مستفيد السوق بعد نقل fixture: `68 PASS /1067 assertions`؛ archive `88dd2a57-b226-45c1-9d21-e947d4f3f4bd-tests.xml` داخل run9c5. اختيار سابق146أنتج91PASS و55خطأ إعداد من fixture يصنف Workspace؛ لم يُخفّف حارس التقاعد لإصلاحه. ترقية/تنظيف trigger تشخيصيتان فشلتا قبل assertions ثم صُححتا بنمط guard_effect/temporary install privilege القائم، وتغطيهما نتيجة21 النهائية.
- `BusinessFactFinalAuthorityTest` وPurpose temporal/DTM/authority وفرق Business/الجهة وعزل Business/history والاستيراد المؤهل وCore guard ضمن91حالة الناجحة في الاختيار السابق؛ ليست نتيجة شاملة146PASS. تعديل حماية الاسم لاحقًا أعيد في الاختيار النهائي؛ تعديل سحب الواجهة له اختبار مكوّن ومتصفح مستقل.
- متصفح Purpose النهائي:3PASS في `purpose-final-browser.json` مع source-manifest المطابق وsource-unchanged=True: reference login/create/open، تفعيل المصدر الحقيقي ثم declare/correct/withdraw + exact lostACK retry و390px، تنازل UI ثم إعادة تأهيل جديدة وحجب القراءة المتأخرة بعد سحب الأصل. لا external authority مُنحت.
- متصفح الفرق:3حالات ناجحة من attempt1، المصدر2حالتين من attempt3، التاريخ المؤهل3حالات من attempt4. كل محاولة تحتوي أيضًا فشلًا لاحقًا محفوظًا: محدد تنقل قديم أو نقص fixture التاريخية أو عدم فتح تبويب Purpose/انتظار شاهد التحديث. لا تُعرض المحاولات كاملة كـPASS. رفض الكاتب/الأصول المؤهلة ثبت مستقلًا؛ ليست هذه بيانات عملاء حقيقية.
- متصفح التاريخ/دورة الحياة:6PASS ومحاولة أخيرة مرفوضة بسبب استدعاء مراقب account غير الموافق؛ جميع التنقلات والشاهد والتجديد الحقيقي نجحت. أعيدت حالتا lease/terminal closure فقط بعد اختيار مراقب fixture الصحيح:3PASS (رفض القديم + التجديد119000ms أثناء read معلّق + منع late renewal بعد canonical revoke)، `lifecycle-final-browser.json`. لا تعديل ساعة/timeout/صلاحيات ولا ادعاء native BFCache/جهاز فعلي.
- JavaScript: الاختيار الحالي للواجهة/العقود/Purpose/CI guards62PASS؛ اختيار transport/router/presentation/lifecycle55PASS؛ المتأثر بعد سحب الواجهة46PASS. الاختيارات تتداخل ولا تُجمع. `vue-tsc`/production build، OpenAPI/types، scoped Pint، `inertia:check` و`runtime-retirement --assets` PASS. لا تشغيل `npm test` الشامل أو Foundation.
- كتّاب SQL المستهدفة وأدوات CLI القديمة مغلقة؛ fixture historique لا تعمل إلا على قاعدة disposable معلّمة وتكتب قبل600 ثم ترفعها إلى620. السجلات/UUID/المراجعات/المنح الأصلية لم تُنسب إلى نشاط تخمينًا. Preview كان متوقفًا عند بدء هذا الاستكمال وبقي كذلك؛ موارد الاختبار بدأت وتوقفت بواسطة runner فقط.

## جرد الملفات الفعلي المتأثر

كل صف أدناه مسار فعلي في diff المرجع/الشجرة، مع تصرف محدد. مجلدات التصميم غير التابعة للتكليف مستبعدة ومحفوظة دون تعديل. ملفات الترحيلات الأقدم/تعريفات SQL التاريخية غير المعدلة تغطيها مصفوفة SQL أعلاه، لا تُحذف لمجرد ظهور رمز قديم فيها.

| المسار | التصرف | الغرض |
|---|---|---|
| `app/Business/AdvertiserGoalPreferences.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Business/BusinessContext.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Business/ObjectPurpose.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Foundation/Business/BusinessScope.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Foundation/Tenancy/OrganizationTeam.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Foundation/Tenancy/WorkspaceContext.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Http/Controllers/AdvertiserGoalPreferencesController.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `app/Http/Controllers/BusinessContextController.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `app/Http/Controllers/BusinessFoundationController.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Http/Controllers/GoalsSpaController.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `app/Http/Controllers/LegacyPurposeHistoryController.php` | تاريخ ضروري | قراءة/تصحيح الأصل بحدوده وحقوقه، دون inferred Business |
| `app/Http/Controllers/ObjectPurposeController.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `app/Http/Controllers/OrganizationController.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Http/Controllers/OrganizationTeamController.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Http/Controllers/RetiredWorkspaceCommercialController.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Http/Controllers/WorkspaceCommercialHistoryController.php` | تاريخ ضروري | قراءة/تصحيح الأصل بحدوده وحقوقه، دون inferred Business |
| `app/Http/Controllers/WorkspaceController.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Http/Middleware/EnsureBusinessContext.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `app/Http/Middleware/EnsureCurrentPrincipal.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Http/Middleware/RestoreBusinessAfterCallback.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `app/Models/Workspace.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/Google/GoogleOAuthState.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/Google/GoogleReadJob.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/Meta/MetaOAuthState.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/Meta/MetaReadJob.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/PersistObservationJob.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/Snapchat/SnapchatOAuthState.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/Snapchat/SnapchatReadJob.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/TikTok/TikTokOAuthState.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/TikTok/TikTokReadJob.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/X/XOAuthState.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Providers/Ads/X/XReadJob.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Security/GrantAuthority.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Security/ServiceWorkspaceContext.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `app/Security/WorkspaceAuthority.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `contracts/application.openapi.json` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `contracts/goals.openapi.json` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `contracts/spa-migration.json` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `database/migrations/0001_01_01_000570_business_declaration_team.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/migrations/0001_01_01_000580_organization_team.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/migrations/0001_01_01_000590_organization_resources.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/migrations/0001_01_01_000600_retire_workspace_commercial_facts.php` | تقاعد/تاريخ | تقاعد قابلية التشغيل مع حفظ الأصول والحقوق |
| `database/migrations/0001_01_01_000610_retire_workspace_purpose_declarations.php` | تقاعد/تاريخ | تقاعد قابلية التشغيل مع حفظ الأصول والحقوق |
| `database/migrations/0001_01_01_000620_business_purpose_statement_policy.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/authority.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/business_source_visibility.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/commercial_retirement.sql` | تقاعد/تاريخ | تقاعد قابلية التشغيل مع حفظ الأصول والحقوق |
| `database/sql/business_scope/direct_team.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/grant_commands.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/history.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/legacy_history.sql` | تاريخ ضروري | قراءة/تصحيح الأصل بحدوده وحقوقه، دون inferred Business |
| `database/sql/business_scope/organization_resources.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/organization_team.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/purpose_retirement.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/purpose_statement_policy.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/retired_legacy_inventory.sql` | تاريخ ضروري | قراءة/تصحيح الأصل بحدوده وحقوقه، دون inferred Business |
| `database/sql/business_scope/scope_authority.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `database/sql/business_scope/surfaces.sql` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `docs/BUILDERAD_EXECUTABLE_ARCHITECTURE.md` | احتفاظ مبرر | عقد/جرد/أدلة حالية مع فصل التاريخ عن قبول المرشح |
| `docs/BUILDERAD_TEST_EXECUTION_RATIONALIZATION.md` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `docs/INERTIA_RETIREMENT_MATRIX.md` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `docs/execution/business-foundation/INTERNAL_CLIENT_FILE_AUTHORITY.md` | احتفاظ مبرر | عقد/جرد/أدلة حالية مع فصل التاريخ عن قبول المرشح |
| `docs/execution/business-foundation/PR56_RETIREMENT_MATRIX.md` | احتفاظ مبرر | عقد/جرد/أدلة حالية مع فصل التاريخ عن قبول المرشح |
| `docs/execution/business-foundation/PROGRESS.md` | احتفاظ مبرر | عقد/جرد/أدلة حالية مع فصل التاريخ عن قبول المرشح |
| `docs/execution/public-platform/SURFACES.md` | احتفاظ مبرر | عقد/جرد/أدلة حالية مع فصل التاريخ عن قبول المرشح |
| `docs/execution/structural-foundation/IMPLEMENTATION_PROGRESS.md` | احتفاظ مبرر | عقد/جرد/أدلة حالية مع فصل التاريخ عن قبول المرشح |
| `docs/inertia-inventory.json` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `package.json` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `resources/js/Components/Business/CurrentIntentEditor.vue` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/Components/Business/ObjectPurposeEditor.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/Components/Business/TargetMarketsEditor.vue` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/api/generated/application.d.ts` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `resources/js/api/generated/goals.d.ts` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `resources/js/business-context/BusinessContext.vue` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/business-context/ContextForm.vue` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/business-context/api.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/business-context/commands.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/business-context/drafts.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/business-context/routes.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/business-context/state.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/business-foundation/AdvertiserWorkspaceLayout.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/BusinessDelegation.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/BusinessPurposes.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/BusinessSources.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/BusinessWorkspace.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/DirectBusinessTeam.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/PurposeStatementEnrollment.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/advertiserNavigation.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/api.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/business-foundation/drafts.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/goals-spa/GoalsEditor.vue` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/goals-spa/GoalsReview.vue` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/goals-spa/api.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/goals-spa/drafts.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/goals-spa/paths.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/goals-spa/proof.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/goals-spa/routes.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/goals-spa/state.ts` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/object-purpose/ObjectPurposes.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/object-purpose/PurposeView.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/object-purpose/api.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/object-purpose/routes.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/sources/SourceView.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/sources/Sources.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/spa/main.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/workspaces/BusinessCreate.vue` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `resources/js/workspaces/CommercialHistory.vue` | تاريخ ضروري | قراءة/تصحيح الأصل بحدوده وحقوقه، دون inferred Business |
| `resources/js/workspaces/OrganizationCreate.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/workspaces/OrganizationTeam.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/workspaces/WorkspaceIndex.vue` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/workspaces/api.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/workspaces/commercialHistory.ts` | تاريخ ضروري | قراءة/تصحيح الأصل بحدوده وحقوقه، دون inferred Business |
| `resources/js/workspaces/routes.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/workspaces/state.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `resources/js/workspaces/team.ts` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `routes/web.php` | نقل/احتفاظ مبرر | Business facts أو الجهة/المصدر المستقل وفق حدود المسؤولية أعلاه |
| `scripts/Test-DeclaredTargetMarkets.ps1` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `scripts/Test-GoalsSpa.ps1` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `scripts/application-openapi.mjs` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `scripts/business-foundation-openapi.mjs` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `scripts/goals-openapi.mjs` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `scripts/inertia-inventory.mjs` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `scripts/sources-openapi.mjs` | نقل | العقود والعملاء وأوامر CI تستهدف المالك الحالي؛ لا مسار كتابة تجاري قديم |
| `tests/AdvertiserGoalsCommandWorker.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/CurrentIntentCommandWorker.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/AdvertiserGoalPreferencesTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/ArchitectureSourceOptionalityTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessContextLockOrderTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/BusinessDirectTeamTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessFactFinalAuthorityTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessFoundationContractTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessFoundationTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessIntentOptionsTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessPreferenceVariantsTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessPurposeAuthorityTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessPurposeDtmLineageTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessPurposeStatementPolicyTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessPurposeTemporalTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessPurposeTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/BusinessQualifiedImportTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/Commercial/CommercialMarketPreparationTest.php` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `tests/Feature/Commercial/CommercialScopeTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/CountryReferenceProvenanceTest.php` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `tests/Feature/CurrentIntentConcurrencyTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/CurrentIntentDatabaseTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/CurrentIntentNormalizationTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/CurrentIntentSecurityTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/CurrentIntentTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/DeclaredTargetMarketsConcurrencyTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/DeclaredTargetMarketsDatabaseTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/DeclaredTargetMarketsSecurityTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/DeclaredTargetMarketsTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/GoalsSpaIntegrationTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/GoalsSpaProofTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/LegacyCohortFenceTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/LegacyPurposeHistoryTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/ObjectPurposeConcurrencyTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/ObjectPurposeDatabaseTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/ObjectPurposeDtmLineageTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/ObjectPurposeHttpTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/ObjectPurposeSecurityTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/ObjectPurposeTest.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Feature/OrganizationRegistrationTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/OrganizationResourcesUpgradeTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/OrganizationTeamTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/Providers/ProviderAccessStoreTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/Security/CustomerAdministrationTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/Security/LifecycleActivationTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/Security/OwnerGrantReassessmentTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/Security/WorkspaceCreationTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/Sources/SourceRegistryTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/WorkspaceCommercialRetirementTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Feature/WorkspaceSpaIntegrationTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/ObjectPurposeCommandWorker.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/BusinessIntentOptionsTestCase.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/BusinessPurposeBrowserFixture.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/BusinessPurposeTestCase.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/BusinessSurfaceFixture.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/CurrentIntentDurabilityProof.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/DeclaredTargetMarketsDurabilityProof.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/DeclaredTargetMarketsLegacyFixture.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/DeclaredTargetMarketsUiFixture.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/DisposableDatabaseReset.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/GoalsSpaFixture.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/GoalsSpaLockWorker.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/LegacyCommercialUpgradeFixture.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/LegacyHistoryBrowserFixture.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/ObjectPurposeTestCase.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/PurposeDeclarationFixture.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/auth-entry-browser.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/business-context-spa-browser.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/business-surface-browser.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/declared-target-markets-browser.mjs` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `tests/Support/focused-spa-acceptance.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/goals-api-contract.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/goals-spa-browser.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/goals-spa-closure.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/goals-spa-restoration.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/goals-spa-routes.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/goals-spa-server.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/object-purpose-spa-browser.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/sources-spa-browser.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/sources-spa-fixture.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/spa-foundation-browser.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Support/workspace-retirement-browser.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/Support/workspace-spa-browser.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/TargetMarketsCommandWorker.php` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/Unit/CoreDependencyBoundaryTest.php` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/advertiser-workspace.test.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/application-api-client.test.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/business-context-commands.test.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/current-intent-interaction.test.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/goals-api-client.test.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/object-purpose-interaction.test.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
| `tests/object-purpose-spa.test.mjs` | نقل/احتفاظ مبرر | العقد/المستهلك/دليل التحقق لدى المالك الحالي وفق الحدود المذكورة أعلاه |
| `tests/spa-goals-interaction.test.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/target-markets-interaction.test.mjs` | تقاعد | كاتب/محرر/driver للسلوك المتقاعد؛ البديل والإثبات في المصفوفة أعلاه |
| `tests/workspace-commercial-retirement.test.mjs` | نقل | اختبار العقد عند المالك الحالي أو رفض القديم/حماية الأصل والترقية |
