# BuilderAd — إغلاق دفعة Goals SPA + OpenAPI + Typed Client

التاريخ: 2026-10-17. شجرة التنفيذ: `C:/dev/builderad-worktrees/ba-spa-openapi-001`، الفرع `codex/ba-spa-openapi-001`.

## 1. الحكم

**اكتملت الدفعة البرمجية محليًا، واجتازت الفحوص الشاملة المطلوبة. لم يُفتح قبول الترحيل العام أو النشر.**

| الحكم المستقل | الحالة النهائية | الدليل والحد |
|---|---|---|
| A — Session Continuity | **PASS محليًا ضمن النطاق المعتمد** | أُعيد تشغيل الانقلاب وتجديدات متزامنة والخروج والإبطال والاحتفاظ الفعلي بالـcookie على الشيفرة المنقولة؛ وليس نقلًا لحكم التقرير السابق |
| B — Goals SPA Proof | **BLOCKED** | حالتا native BFCache في SPA بقيتا UNVERIFIED؛ لم تُختبر أجهزة فعلية أو سرية cached frames قبل JavaScript مباشرة |
| C — Overall Execution Gate | **BLOCKED** | أسباب حجب B باقية؛ لا موافقة ضمنية على التوسع أو حذف Inertia/N1 أو النشر |

لا Commit أو staging أو Push أو PR أو Merge أو نشر. لا ملفات متتبعة محذوفة. شجرة المصدر السابقة لم تتغير. لا تعديل دلالي جديد على منظومة الأمان المشتركة؛ التغيير الأمني بالنسبة إلى HEAD هو **إعادة استعمال التصحيح المعتمد السابق نفسه**، مع اختبارات رجوع جديدة على هذه الشجرة.

## 2. القاعدة والجرد والنقل

HEAD للقاعدة وشجرة المصدر وشجرة التنفيذ: `580837d334ac703209644ffff54bb6d05a62a0c0`. Tree الملتزم: `5706ebc15adcb0bda4ae2a1c53f7ba335ecbc3ac`. شجرة التنفيذ والقاعدة كانتا نظيفتين قبل النقل. شجرة المصدر `codex/ba-vue-spa-001` تحتوي العمل غير الملتزم المسجل في الجرد.

- [SOURCE_PROVENANCE.md](SOURCE_PROVENANCE.md): أسباب النقل والاعتماديات والفحوص لكل مجموعة وملف.
- [source-inventory.json](source-inventory.json): الجرد الأول وحالة Git وSHA-256 لكل الملفات المرشحة، بما فيها التقريرين التاريخيين.
- [import-map.json](import-map.json): المصدر والوجهة والبصمتان وتصنيف `REUSE EXACT / ADAPT / READ HISTORICAL EVIDENCE ONLY` لكل ملف.
- [continuity-equality.json](continuity-equality.json): تطابق بايتات تصحيح الجلسة وعميله واختباراته المحددة مع المصدر المعتمد.
- [dependency-inventory.json](dependency-inventory.json): أثر إضافة أدوات العقد، والتراخيص، وإدخالات القفل المضافة والمزالة.

فُحصت بصمات **32 ملفًا مرجعيًا** عند الإغلاق، وظلت كلها مطابقة للجرد الأول. استُوردت الشيفرة والاختبارات والمشغّلات الضرورية فقط. قُرئ التقرير الأول ومرجع إغلاق Session Continuity؛ لم تُنقل ملفات تقارير التجربة ولا أرشيفاتها ولا قواعد بياناتها ولا `.env` أو مجلدات dependencies. ثُبتت التبعيات في شجرة التنفيذ نفسها.

آخر CI ناجح للقاعدة متاح في [Foundation 38576079091](https://github.com/42Huss/builderad/actions/runs/38576079091) عند HEAD المذكور؛ مصدر هذه المعلومة metadata تاريخية محفوظة بالتجربة السابقة. **لا تُنسب نتيجة ذلك CI إلى المرشح غير الملتزم الحالي.** لا CI جديد لهذا المرشح لأن نشره غير مأذون.

## 3. التغييرات الفعلية

### KEEP / REUSE

بقيت `AdvertiserGoalPreferencesController` وخدمة `AdvertiserGoalPreferences` وSQL الكاتب و`SessionAuthority` و`WorkspaceContext` وRLS والمهاجرات والأقفال دون تغيير. تحقّق `git diff` من هذه الحدود ومن `compat/inertia-n1` دون فرق.

أُعيد استعمال تصحيح `IdentityCookies / IdentityDocument / IdentityRenewal / IdentityTransition` و`sessionLease.ts` وتعديل عميل الهوية واختباراته بايتًا ببايت. يحافظ التجديد على Redis والجلسة الحالية دون إعادة نشر cookies؛ يصدر انتقال الدخول المملوك cookie بعمر لا يتجاوز absolute deadline. لا alias يتبع principal جديدًا، ولا ترقية صامتة لحدود legacy، ولا تمديد لسلطة SQL.

أُعيد استعمال Vue Router وTanStack Query والمسودات الذاكرية ومراجعة CAS وآليات رفض القراءة المتأخرة وعرض المستند المقيد بهوية واحدة من تجربة Goals. لم تُبنَ نواة History أو crypto queue أو كاتب أعمال ثانٍ.

### ADAPT / REPLACE

| الملفات | التغيير |
|---|---|
| `config/spa.php`, `routes/goals-spa.php`, `routes/web.php` | تسجيل فعلي اختياري لشريحة SPA، مع الحفاظ على `web` و`auth` و`EnsureCurrentPrincipal` وأسماء `workspaces.goals.*` |
| `app/Http/Controllers/GoalsSpaController.php` | قشرة HTML وقراءة سياقية رقيقتان؛ إعادة استخدام projections القائمة وWorkspaceContext، دون كاتب جديد |
| `scripts/goals-openapi.mjs` | مولّد صريح لعقد Laravel القائم؛ لا inference plugin أو مسارات توثيق عامة |
| `contracts/goals.openapi.json` | OpenAPI 3.1 داخل المستودع الخاص، خارج `public/` |
| `resources/js/api/generated/goals.d.ts` | أنواع ومسارات مولّدة باستخدام openapi-typescript |
| `resources/js/api/goals.ts` | factory باستخدام openapi-fetch وأنواع تجارية محايدة؛ لا افتراض لاعتماد المتصفح داخل schema/types |
| `resources/js/goals-spa/api.ts`, `transport.ts` | ربط العميل المولد بحد fetch الحالي الذي يتحقق من receipt والهوية والتسلسل؛ الاحتفاظ بأجسام الأخطاء الأصلية |
| `state.ts`, `drafts.ts`, `GoalsEditor.vue` | استهلاك الأنواع والمسارات المولدة في القراءة والحفظ، مع إبقاء witness وquery scope وCAS والمسودات وعدم تكرار POST |
| `main.ts`, `package.json` | فصل بناء production عن بناء `proof`؛ globals الاختبار متاحة فقط في proof |
| `tests/Feature/GoalsSpaIntegrationTest.php` | مسارات الإنتاج الاختيارية ومكدسها والكاتب الحالي وغياب public docs؛ اختبار أن Goals لا تتطلب challenge انتقال الهوية الإضافي |
| `tests/Support/goals-api-contract.mjs` | عميل ذو مسارات مولدة أمام HTTP الحقيقي، والتحقق من أجسام الردود بـAjv |
| `tests/goals-api-client.test.mjs` | ثلاثة اختبارات لحمولة الكاتب، وعدم retry عند فقد الإقرار، وتمييز تعارض CAS عن تعارض الهوية |
| `.github/workflows/foundation.yml` | إضافة `api:check` وبناء SPA إلى Foundation القائمة؛ لم يُشغّل CI منشور |

المسارات القائمة `GET/POST /workspaces/{workspace}/goals` مستمرة. JSON GET وPOST موثقان ويستهلكهما العميل المولد. القراءة السياقية القديمة من التجربة تبقى مسؤولة عن workspace/business/catalog/visibility اللازمة للقشرة؛ تستدعي الخدمات القائمة، ولا تضيف خوارزمية أعمال.

**التفعيل اختياري ومغلق افتراضيًا:** `GOALS_SPA_ENABLED=true` يسجل `/spa/workspaces/{workspace}/goals[/review]` و`/spa/api/workspaces/{workspace}/goals`. هذه إعدادات تشغيل محلية/مراجعة، وليست تفعيلًا منفذًا على خدمة قائمة. فحص `route:list` تحت `APP_ENV=production` أكد صفر مسارات `spa/*` عند التعطيل ومسارين عند التفعيل، كلاهما عبر `web/auth/EnsureCurrentPrincipal`.

### العقد والتبعيات

تفصيل العقد والحدود وأوامر التوليد في [GOALS_API_CONTRACT.md](GOALS_API_CONTRACT.md). الأوامر: `npm run api:generate` و`npm run api:check`.

الأدوات المباشرة الجديدة: `openapi-typescript 7.13.0` و`openapi-fetch 0.17.0` و`ajv 8.20.0`، تراخيصها MIT. الأولى وAjv ضمن dev dependencies. لم يُضف Scramble؛ الوصف الصريح مع اختبار الاستجابة الفعلية مناسب للحد الحالي الذي يفك JSON ويتحقق منه يدويًا.

أضاف npm 29 مدخلًا إلى القفل بالنسبة إلى شجرة التجربة، ولم يغير إصدار أي حزمة موجودة. أزال 57 مدخلًا كانت كلها `optional + peer`، ومنها esbuild/stylus وتبعياتهما الاختيارية. بقيت حزم Linux x64 الخاصة بـRolldown/Tailwind/LightningCSS. توثق قائمة التبعيات التفاصيل والتراخيص؛ تغيير تنسيق القفل لا يمثل ترقية للحزم الموجودة. Composer lock لم يتغير.

الأنواع تصف الأشكال ولا تنقل سلطة الأعمال إلى العميل. تختلف قيمة القراءة الأولية الفارغة عن قيمة POST المقبولة؛ التحقق الدلالي من IDs والأولوية والنتائج والتطبيع باقٍ في الكاتب الحالي. وثقت 401/403 وCAS409 وidentityConflict409 و419 و422 دون تغيير حقل أو معنى خطأ. لا يُفترض أن انتهاء الجلسة يرجع دائمًا 401؛ قد يرفض admission المستند بـ409 أو يرفض CSRF النقل بـ419 أولًا.

## 4. الاختبارات المنفذة على شجرة التنفيذ

جذر الأدلة `storage/framework/testing/` داخل هذه الشجرة. كل مسارات الجدول أدناه تشير إليه.

| البوابة | النتيجة | الدليل |
|---|---|---|
| Laravel كاملة | **971 PASS،13,858 assertions؛errors/failures/skipped=0** | `goals-spa-1eeb237e-ccb1-4fe7-984c-79ceae70b21d/tests.xml`, `tests.log`, `events.log` |
| JavaScript كاملة | **285 PASS؛fail/cancelled/skipped=0** | داخل المجلد نفسه: `gate-npm-test------test-concurrency=1.log` |
| Composer validate / audit | **PASS؛0 advisories** | `gate-composer-validate---strict.log`, `gate-composer-audit.log` |
| npm audit | **PASS؛0 vulnerabilities** | `gate-npm-audit---audit-level=high.log` |
| انحراف العقد والتوليد | **PASS** | `gate-npm-run-api-check.log` |
| Pint كامل | **PASS** | `gate-php-vendor-bin-pint---test.log` |
| TypeScript وVite للتطبيق القائم | **PASS** | `gate-npm-run-build.log` |
| TypeScript وVite لـSPA،production | **PASS** | `gate-npm-run-build-goals-spa.log` |
| المتصفح الشامل والعقد وclosure | **52 حالة:50 PASS،2 UNVERIFIED،0 FAIL؛findings=[]؛failure=null** | `goals-spa-500b054a-9894-4fef-883d-b3a87605bb88/browser-results.json`, `browser.log` |
| الفحوص المتأثرة السابقة للمجموعة النهائية | **79 PASS،929 assertions** | النسخة المؤرشفة `ef59f8fe-3c3e-4d4d-ba74-28b8252fcfea-tests.xml` داخل مجلد Foundation أعلاه |
| تعطيل/تفعيل المسارات تحت production | **PASS للفحص المحدد** | `spa-openapi-gates/route-switch-summary.json` والقائمتان الخام |
| غياب globals الاختبار والمواصفة العامة عن artifact الإنتاجي | **PASS للفحص المحدد** | `spa-openapi-gates/production-artifact-check.json` |

استدعاء Foundation النهائي **خرج0** بعد إكمال جميع بواباته وإيقاف العمليات. مدة console لمجموعة Laravel:2097.28s؛مرجع العد والأخطاء هو JUnit. تشغيل ملفات Node تسلسلي بـ`--test-concurrency=1`، دون تعطيل التزامن الحقيقي داخل حالات الاختبار.

استدعاء المتصفح تعمد الخروج2، وانتهى مشغّله بحالة غير صفرية **لأن معيار BFCache غير مثبت**. لا يُحوّل ذلك إلى PASS شامل، ولا يُسجل كفشل assertion أو عيب تطبيقي جديد.

## 5. الرحلات الأمنية والوظيفية المثبتة

| الرحلة | النتيجة وحدود الدليل |
|---|---|
| GET/POST عبر العميل المولد | HTTP Laravel حقيقي:200/401/403/409/419/422؛Ajv 2020-12 تحقق من الأجسام الفعلية، مع قراءة إضافية من داخل SPA عبر fetch الذي يتحقق من الهوية |
| عزل Workspace وRLS | رفض النطاق الأجنبي403؛RLS وFORCE مفعّلان؛runtime بلا السياق الموقع رأى صفر صفوف Goals |
| CAS والمسودات | stale409 يحافظ على المسودة؛مراجعة واعتماد صريحان؛لا أثر مكرر؛reload يعيد canonical وفق العقد السابق |
| قراءة متأخرة وتنقل سريع وBack | لا نشر لبيانات Workspace تم تجاوزه؛Vue Router يملك التنقل وTanStack يملك query؛لا تحميل مستند كامل في التنقل العادي المختبر |
| سحب المنحة داخل معاملة SQL | في كلا المسارين رُصد `builderad_app` داخل `builderad_advertiser_goals_write` منتظرًا advisory lock،و`pg_blocking_pids` يطابق holder المسجل؛سُحبت المنحة قبل تحرير القفل،فكانت403 وeffectDelta=0 |
| فقد الإقرار بعد commit | رُفض تسليم رد200 الحقيقي بعد إثبات commit؛ظهرت رسالة عدم اليقين؛automaticWrites=1،إعادة صريحة بإصدار قديم409،مراجعة/تسوية200،effectDelta=1 في كلا المسارين |
| renewal/login inversion | وصل دخول B وثبت context200 ثم سُلّم الرد القديم؛لم ينشر التجديد cookies ولم يطرد B؛رُفضت كتابة المستند القديم |
| تجديدان متزامنان وترتيب تسليم معكوس | بقي credential نفسه دون cookies أو تمديد canonical deadlines؛لا ادعاء أن abort ألغى تنفيذ الخادم |
| الإبطال والخروج مع renewal معلّق | الرد المنتج قبل الإبطال قد يصل،لكن الطلب المحمي التالي يُرفض؛لا إحياء للجلسة أو أثر إضافي |
| تبويبان وWorkspaceان | رفض المستند القديم مع cookie jar الفعلية بعد تبديل الحساب؛النطاق الجديد يعمل والأجنبي يُرفض |
| الاستمرارية الزمنية الفعلية | انتظار133437ms في Inertia و128802ms في SPA،بعد عمر النقل السابق120000ms؛cookies ثابتة،idle/absolute ثابتان،الطلب التالي200 |
| أخطاء المكوّن ودورة الحياة | فشل load/render يمنع العرض الخاص؛الإغلاق terminal لا يعاد فتحه بإشعار lifecycle مصطنع |
| finite SSE | الحدث المصرح يتبع الهوية وWorkspace؛endpoint التجربة فقط،وليس اشتراكًا إنتاجيًا أو bus موثوقًا طويل العمر |

الاختبار داخل SQL يستخدم fixture مخولة في قاعدة معزولة لتغيير المنحة. يثبت نقطة contention المحددة قبل فحص المنحة،ولا يثبت كل نقطة linearization أو رحلة واجهة إدارية كاملة. فقد الإقرار ليس استجابة نجاح مصطنعة؛الـproxy يتخلص من الرد الحقيقي بعد إتمامه.

الاحتفاظ الزمني ليس انتظار24 ساعة أو اختبار إغلاق جهاز. Expires المعلن يطابق canonical absolute؛تسجل الأدلة تعديل ساعة المتصفح الجزئي الناتج عن Date/Max-Age،ولا تفترض أن حذف المتصفح للـcookie يقع في نفس لحظة SQL الدقيقة.

## 6. ثبات المصدر وbytes وحدود الفحوص

المتصفح تحقق من **2568 استجابة asset مُمررة و346 ملاحظة متصفح**. الحد المقاس هو Buffer استجابة PHP الفعلية الذي يمرره الـproxy دون تغيير إلى رد HTTP المواجه للمتصفح،مع مطابقة الملف المحلي وربط URL/status التي رصدها المتصفح. ليس هذا ادعاء إتاحة أجسام CDP بعد إخلاء الوثائق. `servedAssetsVerified=true`.

جولة المتصفح بُنيت في mode=`proof` لخطافات القياس الموجودة؛البناء النهائي هو production بلا `__goalsSpa`. لم تُجرَ جولة متصفح شاملة ثانية على artifact production النهائي،ولا يُنسب دليل bytes الخاص بـproof إليه. البناء النهائي وفحص غياب globals مكتملان؛لا ادعاء HTTPS أو readiness للنشر.

`source-unchanged.txt` في جولة المتصفح هو **False**: أُضيف أثناءها ملفا `continuity-equality.json` و`dependency-inventory.json` فقط،ولم يتغير أي ملف كان في اللقطة. يحفظ `verification-bridge-before-final.json` المقارنة الصريحة. بعد الجولة صحح وصف security requirement في المواصفة وأضيف تحقق integration له،وصُحح سجل المشغّل واختبار وحدة وشرح الوثيقة؛لم تتغير شيفرة التطبيق. `final-source-bridge.json` يسجل الفروق،و`schema-bridge.json` يعيد بناء بصمة المواصفة الأصلية تمامًا بإعادة شرط transition غير المطلوب؛بالتالي أشكال الاستجابات المختبرة لم تتغير.

`source-unchanged.txt` في Foundation أيضًا **False** لأن اللقطة تتضمن manifest البناءين،وقد أعيد توليدهما من proof إلى production. `verification-bridge.json` يثبت أن هذين المخرجين هما الفرق الوحيد قبل كتابة وثائق الإغلاق: **كل ملفات التطبيق والاختبارات والتبعيات والعقد بقيت مطابقة** طوال فحوص Foundation النهائية. ملفات التقرير والجرد النهائية لا تغير التنفيذ؛`final-candidate-manifest.json` يربط الحالة النهائية ببصماتها.

ظهرت أولًا قيود شبكة/كتابة أثناء تثبيت التبعيات داخل sandbox؛نجح التثبيت المأذون في شجرة التنفيذ،ثم نجحت البوابات. محاولة Foundation أسبق توقفت قبل أي اختبار بسبب تمرير مسار Windows مطلق لسجل events؛صُحح السجل إلى مسار نسبي وشُغلت المجموعة كاملة. خلّفت تلك المحاولة ملفًا جديدًا باسم `C` بطول200 بايت في جذر الشجرة؛حُفظ بعد اكتمال التحقق باسم `failed-absolute-path-events.log` داخل مجلد الأدلة نفسه،مع مطابقة SHA-256 وسجل `diagnostic-log-relocation.json`. هو أثر أداة أنشأته المهمة،وليس ملف مصدر حُذف. المحاولات غير الناجحة محفوظة ولا تُصنف PASS. تأخر استلام خرج الأداة مرة لا يغير نتيجة JUnit أو exitcode.

## 7. البيئة وحدود الأدلة المفتوحة

Laravel الحقيقي،PHP8.5،Node24،PostgreSQL18،Redis منفصل على6397،وخادما PHP8136/8137 وproxy8138؛Chromium محلي. بيانات اصطناعية فقط،قاعدة `builderad_test_disposable` وUUID marker،وأدوار app/owner/authority الفعلية؛الحارس الرسمي تحقق قبل التشغيل. لا قاعدة أو Redis لشجرة المصدر أو موارد عمل أخرى. اختبارات HTTP تستخدم APP_ENV=local لتفعيل CSRF الحقيقية،ومحاكاة DB/fixtures تحت guard testing. تختلف البيئة عن CI Ubuntu/Redis7 والإنتاج.

حدود غير مغلقة:

1. **native BFCache في SPA:** لم يرصد `pageshow.persisted=true` موثوقًا في حالتي expiry/switch. سجل Chromium `MainResourceHasCacheControlNoStore` و`JsNetworkRequestReceivedCacheControlNoStoreResource` و`BrowsingInstanceNotSwapped`. التحميل الجديد الآمن أو redirect/403 ليس إثبات BFCache. في Inertia رُصدت استعادة أصلية للحالتين.
2. **الأجهزة والإطار قبل JavaScript:** لا دليل جهاز فعلي/خلفية نظام تشغيل أو سرية compositor cached frame قبل التنفيذ. لقطة viewport390px التي فُحصت بصريًا ليست جهازًا حقيقيًا.
3. **Real-time إنتاجي:** finite SSE الاختباري لا يثبت reconnect/gaps/durable delivery أو سحب صلاحية قناة مفتوحة. لم يُنقل endpoint الأحداث الاختباري إلى routes الإنتاج.
4. **منظومة الجلسات خارج التصحيح:** لا ضمان جديد لسباقات login/login أو anonymous/login أو rebind/login أو فقد إقرار login. زيادة احتفاظ cookie حتى الحد المعتمد tradeoff سابق؛السلطة تظل SQL/Redis/admission.
5. **الجوال والـAPI المنشور:** لا مصادقة جهاز أو OAuth أو refresh-token flow أو تطبيق هاتف. الوثيقة تحدد حدود الدفعة التالية؛لا افتراض أن Cookie Auth يصلح للهاتف مباشرة.
6. **أداء وإتاحة الإنتاج:** لا p95 إنتاجي أو load/distributed test أو HTTPS منشور أو قبول شامل للمتصفحات. Ajv يختبر الأشكال للحالات المنفذة مع `validateFormats=false`؛لا يحل محل تفويض الخادم أو كل قيود SQL الدلالية.
7. **تكافؤ المنتج الكامل:** القشرة والمراجعة وتحرير Business Context والقوائم ليست ترحيلًا كاملًا للتجربة التجارية. ما زالت النسخة المرئية مقتبسة من شريحة البرهان؛لا توسع لقدرة تجارية ثانية.

لم تتغير no-store للحصول على BFCache. لم يُعدّل معيار قبول سابق،ولم يوافق المالك هنا على إعفاء B أو C.

## 8. الفصل الممكن وشروط الخطوة التالية

يمكن مراجعة تصحيح الجلسات المشترك كوحدة مستقلة،ثم عقد Goals وأدوات توليده وعميله واختباراته كوحدة واضحة،ثم دمج قشرة SPA الاختيارية مع جميع اعتماديات النقل والعرض. هذا **تقسيم للمراجعة** وليس Commit/PR منفذًا أو إذن دمج. لا تُنقل SPA بإسقاط إصلاح الجلسة المعتمد أو middleware/WorkspaceContext أو witness/query scope.

شروط القبول المتبقية: إغلاق أدلة B الأصلية أو قرار مالك صريح يعدل نطاق قبولها مع إبقاء ضمانات الأمان؛ثم إغلاق C صراحةً. النشر أو الترحيل الواسع أو تغيير سياسة أمنية أو حذف Inertia/N1 أو تنفيذ اعتماد هاتف يحتاج تفويضًا جديدًا. CI على revision منشور بعينه يبقى غير منفذ؛الفحوص المحلية لا تسد هذه الفجوة تلقائيًا.

ما زال ممكنًا مراجعة هذه الدفعة محليًا بتشغيل المسار الاختياري وتوليد العقد،دون فتح الشريحة الثانية أو إزالة المسار القديم. انتهت الاختبارات بعد اجتياز البوابات المطبقة؛لا حاجة لإعادة المجموعة المكلفة لمجرد إنشاء التقرير.

## 9. إغلاق العمليات والحالة المحلية

تحقق مستقل بعد انتهاء Foundation: `pg_ctl status=3` لكلتا نسختي PostgreSQL المملوكتين للمهمة؛لا listeners على5433/6397/8136/8137/8138؛لا PHP/Node/Postgres/Redis مطابقة لأوامر المهمة؛المتصفح والـproxy مغلقان؛`.env` المؤقت غير موجود. بقيت قواعد وأدلة الاختبار محفوظة،دون أداة تنظيف أو حذف شجرة.

الشيفرة محلية غير ملتزم بها عند HEAD المعلن. تقارير وجرد البصمات تبين ما اختُبر فعليًا،وما أعيد استعماله،وما بقي محجوبًا؛**نجاح الدفعة الهندسية لا يساوي قبول الترحيل العام أو جاهزية الإنتاج.**
