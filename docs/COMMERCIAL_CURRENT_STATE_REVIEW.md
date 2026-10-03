# مراجعة Commercial الحالية — 2026-10-03

نطاق المراجعة: `/internal/commercial` وحدوده المباشرة، على شجرة العمل الحالية بما فيها تغييرات Business Foundation غير الملتزمة. لا تغييرات على كود المنتج أو العقود أو الصلاحيات. معاينة المكوّن الحقيقي ببيانات اصطناعية؛ ليست جلسة إنتاج ولا إثباتًا لتوفر بيانات معتمدة أو منح فعلية في النشر.

## النتيجة المطلوبة

| المفتاح | النتيجة |
|---|---|
| COMMERCIAL_CURRENT_STATE | PARTIAL — المسار التجاري الأساسي موجود ويجتاز اختبارات فعلية، لكن تجربة المشغّل وتكامل العرض والاختبارات ليست مكتملة. |
| COMMERCIAL_OPERATOR_PURPOSE | إنشاء سوق بهوية محكومة، توثيق مصدر Catalog معتمد، تكوين إصدار ثابت من المرجع والعملة، اختيار الإصدار المتاح للإسنادات الجديدة، ثم تسجيل سياق أولي لمستفيد محدد ومراجعة أثره وتسليم تدقيقه. |
| CURRENT_CAPABILITIES | خمس عمليات كتابة تجارية؛ قراءة السجل؛ تأكيد المستفيد؛ قراءة السياق؛ استعلام الإيصال؛ استعادة تسليم التدقيق. توجد أيضًا API لمنح الصلاحيات وسحبها وتنفيذ تفويض Owner، دون واجهة Commercial لها. |
| INPUT_COUNT | 12 مفتاحًا مختلفًا داخل النموذج، منها 5 حقول مشتقة للقراءة فقط في التسجيل؛ رمز سبب واحد؛ محددا العملية والهدف؛ مدخلا السياق والإيصال = 17 مفهوم إدخال/اختيار عبر الحالات. معرّف الأمر معروض وليس حقلًا قابلًا للتحرير. الحقول الظاهرة في نموذج العملية، شاملًا محدد العملية والسبب: السوق 4، Catalog 8، الإعدادات 6، الإتاحة 5، التسجيل 9 (بما فيها محدد الهدف). مدخلا الاستعلام إضافيان في جميع الحالات. |
| SYSTEM_GENERATED_VALUES_EXPOSED_AS_INPUTS | configuration_id عند إنشاء إعدادات مولّد UUIDv7 من الخادم لكنه قابل للتحرير؛ expected_revision يُملأ من السوق لكنه قابل للتحرير. حقول التسجيل scope/workspace/configuration/catalog/denomination مشتقة بالفعل لكنها تعرض كمدخلات قراءة فقط. command_id مولّد UUIDv4 ومعروض فقط؛ version مولّد SQL ومعروض فقط. |
| BACKEND_CAPABILITIES_WITH_WEAK_UI | جميع عمليات الكتابة، المنح، الإيصالات، التدقيق، عرض Catalog مستقل عن الإعدادات، وعرض سياقات المستفيدين. |
| UI_CONTROLS_WITH_UNCLEAR_BACKEND_EFFECT | لا عملية تجارية أساسية بلا أثر قابل للتتبع. غموض العرض في كلمة Catalog، والنتيجة/الإيصال، والتأكيد العام، واسم «إعادة محاولة التسليم» لأول تسليم pending. |
| MISSING_OPERATOR_INPUTS | لا بارامتر إلزامي من API العمليات الخمس بلا حقل. الناقص مصدر اختيار/تفسير معتمد لبعض المعرّفات، عرض المتطلبات المسبقة، والمنح الكاملة، لا حقول كتابة إضافية عشوائية. |
| REDUNDANT_OR_DUPLICATED_CONTROLS | حقول التسجيل التقنية تكرر ما اختاره المشغّل؛ مراجع السياق والإيصال تعاد يدويًا بدل روابط. لا دليل على أن صفحة الخدمات أو أنواع النشاط تنفذ نفس الأثر التجاري. |
| AUTHORITY_GAPS | فجوات تقديم: الخيارات لا تطابق دائمًا المنحة الدقيقة؛ صلاحية التسجيل لا تعني صلاحية الربط والإسناد؛ صلاحية قراءة التدقيق لا تعني الاستعادة. لا تجاوز صلاحية مثبت بهذه المراجعة. |
| VALIDATION_UX_GAPS | UUIDv7 لبعض الهويات غير موضح؛ نموذج novalidate؛ أخطاء SQL عدة تختزل إلى commercial؛ القيم المحمولة بين الأسواق/العمليات قد تكون غير صالحة؛ تحديث السجل لا يعيد ملء المراجعة أو حقول التسجيل تلقائيًا. |
| RESULT / RECEIPT_VISIBILITY_GAPS | JSON بدل نتيجة تشغيلية؛ أثر POST ليس سجل الإيصال الكامل؛ الإيصال يتطلب منحة قراءة مستقلة؛ command_id لا يوجد داخل نتيجة الأثر نفسها؛ لا روابط تلقائية من النتيجة إلى الإيصال/السياق؛ حالة التدقيق منفصلة وغير مرتبطة بشرح النتيجة. |
| TECHNICAL_DETAIL_EXPOSED_TOO_EARLY | UUIDs، SHA-256 اليدوي، رمز السبب، المراجعة القابلة للتحرير، JSON، واختيار أهداف التسجيل على هيئة UUID/UUID. |
| ARCHITECTURAL_CONTRADICTIONS | لا تناقض مثبت في ملكية الحقائق التجارية أو الذرية. هناك عدم توافق بين fixtures الاختبارات التجارية وبوابة Business الجديدة، وفجوة ترجمة، وحالة نموذج لا تعكس الخيارات المعروضة. |

## الإثبات وحدوده

- تشغيل `scripts/Test-BusinessFoundation.ps1 -Filter CommercialScopeTest`: **25 ناجحًا، 3 فاشلة، 361 assertion**. قاعدة PostgreSQL 18 جديدة ومعزولة، تحقق حارس الهوية قبل migrations/truncate؛ أوقفها runner وحذف بياناتها.
- نجح اختبار السوقين السعودية والأردن، المرجع والإعدادات غير المكتملة، الإتاحة/التعطيل، التاريخ الثابت، التسجيل، تكرار الأمر، تعارض الطلب، الإيصال، عزل الصلاحيات، rollback عند فشل التدقيق المحلي، الاستعادة بعد فشل custody، حدود SQL/RLS، التزام Catalog المنفصل، وعدة حالات انتظار وانتهاء صلاحية حقيقية.
- فشل اختبارا `test_non_ecommerce_service_reference_uses_the_same_commercial_foundation` و`test_advertiser_membership_is_not_commercial_read_and_workspace_facts_never_select_market` عند GET صفحة المستفيد: 302 بدل 200. التسجيل قبلهما نجح. Fixtures تنشئ workspace دون business_type_id؛ المسار الحالي أصبح يمر عبر EnsureBusinessContext الذي يعيد توجيه هذا النوع من workspace لإكمال Business. هذا تعارض fixtures/مسار دخول؛ لا يثبت فشل حفظ السياق التجاري أو تسربه، ولا يثبت أن العرض بعد إكمال Business يعمل.
- فشل اختبار `test_r2_commercial_admin_expiry_after_grantee_contention_rolls_back_issuance` أثناء تشغيل helper في الجولة الكاملة: `No real waiter observed`، قبل بلوغ POST/assertions المطلوبة. أُعيد وحده على قاعدة جديدة و**نجح: اختبار واحد، 7 assertions** خلال 12.91 ثانية، بما فيها رفض الإصدار بعد expiry وعدم حفظ grant. الحالة اجتازت التحقق المنفرد، لكن عدم استقرار توقيت helper في الجولة الكاملة يبقى مشكلة في موثوقية الاختبار؛ لا تجاوز سلطة مثبت.
- `node node_modules/vue-tsc/bin/vue-tsc.js --noEmit`: ناجح.
- معاينة Vue الحقيقي وCSS/Layout الحقيقيين، بيانات اختبار، عرض ضيق قرابة 481px ومكتبي 1440px. فحص تبديل العملية واختيار السوق وحالة القائمة والترجمة الإنجليزية. لم تنفذ كتابة عبر هذه المعاينة؛ الكتابة اختبرت عبر HTTP Feature tests على قاعدة مؤقتة.
- اختبار N1 durability العادي يرجع مبكرًا دون `SA01_N1_DURABILITY_PHASE`؛ نجاحه في هذه الجولة ليس إعادة إثبات نقل بين تثبيتين بتاريخ معاملات مختلف.

## تصنيف القدرات

| القدرة | حالة السلسلة الخلفية | الحالة التشغيلية للصفحة |
|---|---|---|
| تسجيل هوية سوق | COMPLETE ضمن الاختبارات الحالية؛ إدراج هوية ثابتة وسجل إتاحة بمراجعة 1 | PARTIAL: يلزم هدف Configure ممنوح مسبقًا؛ ليس نموذج «اكتب اسم دولة وأنشئها» حرًا. إعادة تنفيذ بمعرّف جديد لن تعدّل الاسم. |
| توثيق Catalog | COMPLETE لتسجيل provenance ثابت وبقيود الشكل والمنحة | PARTIAL: إدخال reference UUID يدوي، وعدم إظهار المراجع غير المستخدمة بسجل مستقل. لا رفع ملف أو إنشاء منتجات أو تحقق مستقل من ملف المصدر. |
| إنشاء إعدادات ثابتة | COMPLETE؛ إصدار متسلسل، مرجع من نفس السوق ملتزم منفصلًا، عملة صريحة اختيارية | PARTIAL: UUID مولّد قابل للتحرير؛ القيم السابقة محمولة؛ الإصدار غير المكتمل قانوني للحفظ لكنه لا يقبل الإتاحة. |
| الإتاحة/التعطيل | COMPLETE؛ منحة Enable أو Disable، قفل revision، إعدادات كاملة | PARTIAL: حالة القائمة قد لا تطابق القيمة؛ المراجعة يدوية؛ القائمة تعرض إعدادات غير مكتملة أيضًا. |
| التسجيل الأولي للمستفيد | COMPLETE للأثر الأساسي؛ ثلاث منح، مستفيد مؤهل، سياق ذري، تاريخ ثابت | PARTIAL: يعرض أهدافًا حسب منحة Register فقط وأسواقًا حسب الإتاحة فقط؛ نجاح الاختيار الأول لا يثبت كفاية المنح أو توفر القراءة. |
| السجل والتفاصيل | COMPLETE للقراءة الخاضعة لـRLS | PARTIAL: لا اختيار سياقات/مستفيدين مسجلين؛ Catalog الذي لا يرتبط بإعدادات لا يعرض كعنصر مستقل. |
| عرض سياق داخلي | COMPLETE؛ منحة ReadCommercialContextInternal الدقيقة | PARTIAL: UUID يدوي وJSON؛ عدم وجود/عدم السماح يصبح 403 بنفس العرض. |
| عرض الإيصال | COMPLETE؛ قراءة commercial_command_results بمنحة مستقلة | PARTIAL: UUID يدوي وJSON؛ لا تعبئة من الأمر الحالي ولا تاريخ إيصالات. |
| تسليم/استعادة التدقيق | COMPLETE للحفظ المحلي وcustody والاستعادة المجربة | PARTIAL: زر يظهر لمن يقرأ دون أن يملك retry؛ لا هدف/وقت/ack واضح في قائمة الصفحة. |
| إصدار/سحب منح تجارية | COMPLETE للحالات الأساسية المختبرة؛ expiry/contention نجح عند إعادته منفردًا بعد فشل helper في الجولة الكاملة | MISSING في عرض Commercial: API موجود، لا عناصر إدارة منح في الصفحة؛ لا يُستنتج وجوب دمجها هنا. |
| تنفيذ Owner mandate | COMPLETE للحالات الاصطناعية المعتمدة والمرفوضة | MISSING في العرض؛ الإعداد الافتراضي owner_mandates فارغ عمدًا، لا صلاحية حقيقية مستنتجة. |
| عرض المستفيد بعد التهيئة Business | PRESENT_BUT_UNVERIFIED في هذه الجولة | اختباران قديمان يتوقفان عند redirect؛ يلزم اختبار بالمتطلبات الحالية. |
| أسعار/اتفاقيات/مبالغ/نشر Offers | ليست أثرًا موجودًا في هذا المسار | لا تُسجل كفجوة إلزامية: agreement_state وamount_state يبقيان UNKNOWN عمدًا. |

## تدقيق الحقول الدقيق

الاختصارات: **R** = ReadCommercialMarketRegistry على السوق؛ **C** = ConfigureCommercialMarket؛ **K** = RecordOrVerifyApprovedMarketReference على السوق وreference UUID؛ **E/D** = Enable/DisableCommercialMarket؛ **S** = RegisterCommercialScope؛ **B** = RecordBeneficiaryBinding؛ **A** = AssignCommercialMarket على النطاق/المستفيد/configuration UUID. جميع الكتابات تحتاج جلسة internal حالية ومصادقة حديثة، وفحص سلطة SQL عند الأثر. القراءة خاضعة للمنحة/RLS، وليست مشتقة من مجرد ظهور العنصر.

| الحقل / النص الحالي | المعنى والمصدر | التحرير والسلطة | الأثر والتحقق | النجاح / الفشل | مشكلة العرض والتصنيف |
|---|---|---|---|---|---|
| نوع العملية | اختيار واحد من خمس عمليات ثابتة | قرار المشغّل؛ كل الخيارات ظاهرة بغض النظر عن المنح | يغير route وحقول POST؛ ليس تغييرًا محفوظًا وحده | يبدل النموذج / لا أثر خلفي بمجرده | PARTIAL؛ لا يوضح الأهلية ويحتفظ بالقيم والأمر |
| هوية السوق | market_id من markets المرئية، مع أهداف Configure غير المسجلة في غير register | اختيار؛ الكتابة C/K/E/D أو منح التسجيل | UUID؛ الهويات المسجلة في SQL UUIDv7؛ مرجع FK عند الاستهلاك | السوق المستهدف / 403 أو 422 أو 409 | لا يولّد سوقًا عشوائيًا؛ غير register يضيف أهداف Configure حتى في عمليات أخرى؛ PARTIAL |
| اسم العرض | label من المشغّل في market | قابل للتحرير؛ C | إدراج اسم ثابت، string حتى 120 وعدم خلوه بعد trim في SQL | id/label وسجل إتاحة جديد / 422 أو conflict عند تكرار الهوية | ليس تعديل اسم سوق موجود؛ عرض السوق الموجود كهدف ممكن مربك؛ PARTIAL |
| هوية مرجع Catalog — catalog | catalog_reference_id يكتبه المشغّل؛ الهدف المعتمد موجود في targets.version_id لمنحة K | قابل للتحرير؛ K على نفس UUID | UUIDv7، FK سوق، reference PK، catalog identity/version unique | مرجع جديد ثابت / 403 إذا UUID لا يطابق المنحة، 422 شكل، 409 تكرار | يجب اختياره من tuple المنحة عند وجودها، لا توليد UUID حر؛ PARTIAL |
| هوية Catalog | catalog_identity خارجي من المرجع المعتمد | قابل للتحرير؛ K | UUID عادي؛ هوية الكتالوج ليست هوية reference؛ مع version قيد uniqueness | تحفظ في المرجع / 422 أو 409 | المصدر لا يعرض ولا يوجد منتقي مصدر معتمد مثبت؛ ADVANCED INPUT / PARTIAL |
| إصدار Catalog | catalog_version خارجي صريح | قابل للتحرير؛ K | string حتى 80، SQL غير فارغ بعد trim؛ ليس رقم config.version | تحفظ مع الهوية / 422 أو 409 | ليس مولدًا من sequence إعدادات السوق؛ PARTIAL |
| مرجع الموافقة المعتمد | approval_reference خارجي يكتبه المشغّل | قابل للتحرير؛ K | regex أحرف/أرقام . _ : / - وطول 3–120؛ لا lookup في سجل موافقات بهذا المسار | تحفظ provenance / 422 | لا تستنتج أن النص وحده يثبت موافقة قانونية؛ PARTIAL |
| SHA-256 للمصدر المعتمد | source_sha256 خارجي يدوي | قابل للتحرير؛ K | 64 خانة hexadecimal صغيرة؛ لا ملف مصدر هنا لحسابها أو مقارنتها | تحفظ بصمة مصدر / 422 | لا تخلط ببصمة الطلب/التدقيق؛ لا يمكن اشتقاقها من canonical file غير موجود في السلسلة؛ PARTIAL |
| هوية إصدار الإعدادات — configuration | configuration_id مولّد UUIDv7 في index | قابل للتحرير رغم توليده؛ C | UUIDv7 وPK؛ version مستقل مولّد SQL | id/version/reference/currency / 422 أو 409 | SYSTEM-GENERATED EXPOSED AS INPUT؛ PARTIAL |
| هوية مرجع Catalog — configuration | اختيار من catalogReferences الخاصة بالسوق | قرار؛ C مع R لعرض المراجع | nullable UUID، FK tuple السوق، separately committed catalog | إصدار مكتمل أو غير مكتمل / 422 | يمكن حفظ فارغ؛ لا عرض مستقل للمراجع؛ PARTIAL |
| سياق العملة — configuration | denomination_code صريح من المشغّل | قرار؛ C | nullable، ثلاثة أحرف كبيرة؛ ليس تحقق ISO membership ولا اشتقاقًا من السوق | إصدار ثابت / 422 | العملة قرار حقيقي؛ رمز مطابق للشكل لا يثبت عملة مدعومة تجاريًا؛ PARTIAL |
| هوية إصدار الإعدادات — assignability | اختيار config للسوق أو null للتعطيل | قرار؛ E للقيمة غير null وD لـnull | إعدادات كاملة من السوق نفسه؛ Catalog ملتزم؛ CAS revision | configuration_id/revision جديد / 403،422،409 | قائمة تشمل غير المكتمل، وقيمة محمولة خارج الخيارات ممكنة؛ PARTIAL |
| المراجعة الحالية للإتاحة | expected_revision من markets.revision عند selectMarket | مشتقة لكن قابلة للتحرير؛ E/D | integer min 1؛ يجب تطابق المراجعة المقفلة | زيادة revision / 409 عند stale | لا تزال ضمانة concurrency لازمة حتى لو أخفي إدخالها؛ تحديث props لا يعيد ملء الحقل؛ PARTIAL |
| اختر النطاق والمستفيد المشمولين بالمنحة | scopeTargets من منح S وworkspace public resolver | اختيار؛ S فقط لتأكيد المرجع | GET workspace-reference يستخرج workspace active ويطلب S | اسم المستفيد وهويته / رسالة تحقق عامة | لا يثبت B/A ولا أهلية التسجيل النهائية؛ أسماء تظهر بعد الاختيار فقط؛ PARTIAL |
| هوية النطاق المشمولة بالمنحة | scope_id من الهدف المختار | readonly في register؛ S+B+A | UUIDv7؛ scope جديد unique | سياق جديد / 403،422،409 | READ-ONLY SYSTEM REFERENCE معروض كحقل نموذج؛ تكرار |
| هوية مساحة العمل المستفيدة | workspace_public_id من tuple المنحة | readonly؛ S+B+A على workspace داخلي مطابق | UUID، active workspace، owner lifecycle/membership/qualification تحت أقفال | beneficiary binding / 403 أو409 منافسة | لا يطلب رقم workspace الداخلي؛ تكرار تقني |
| هوية إصدار الإعدادات — register | configuration_id من market.configuration_id عند اختيار السوق | readonly؛ A على هذا UUID | يجب أن يكون الإصدار المتاح حاليًا وأن يطابق catalog/currency | assignment ثابت / 403،422،409 | يبدأ بمعرّف «إصدار جديد» قبل اختيار السوق؛ لا يعاد الاشتقاق عند reload props؛ PARTIAL |
| هوية مرجع Catalog — register | من الإعدادات المختارة | readonly؛ ضمن S+B+A | required UUID، exact config/catalog tuple ومصدر ملتزم | المرجع في assignment/result / 422 | مشتق بالفعل، يفضل عرضه بمسمى مفهوم؛ تكرار |
| سياق العملة — register | من الإعدادات المختارة | readonly؛ ضمن S+B+A | required ثلاثة أحرف كبيرة وتطابق config | يحفظ السياق / 422 | ليس قرارًا جديدًا في التسجيل؛ تكرار |
| رمز السبب — دون بيانات شخصية | reason_code، يبدأ commercial_configuration لجميع العمليات | قابل للتحرير؛ يلحق بالأمر تحت السلطة نفسها | regex 3–64، يبدأ حرفًا صغيرًا، lower/digit/._-؛ يدخل fingerprint | محفوظ في receipt/audit / 422؛ تغييره بنفس أمر ناجح409 | قرار تدقيق مقصود، لا يشتق آليًا دون سياسة؛ النص «دون بيانات شخصية» توجيه لا فحص دلالي؛ PARTIAL |
| Command | crypto.randomUUID في المتصفح | معروض فقط، زر منفصل لتجديده | UUID؛ command PK مع fingerprint canonical JSONB وadvisory lock | نفس payload يعيد نفس result / مختلف409، وسلطة جديدة لازمة حتى للإعادة | idempotency key نفسه command_id؛ لا حقل آخر؛ يضيع عند reload كامل؛ PARTIAL |
| هوية النطاق للعرض الداخلي | contextScope يدوي | قابل للتحرير؛ ReadCommercialContextInternal | route whereUuid + RLS | CONTEXT_RECORDED مع context وUNKNOWN / 403 أو404 برسالة واحدة | يمكن وصل سياق التسجيل بدل إعادة النسخ؛ PARTIAL |
| معرّف الأمر — استعلام | receipt يدوي | قابل للتحرير؛ ReadCommercialRegistrationReceipt على target/workspace | route whereUuid + RLS | صف الإيصال الكامل / 403 أو404 برسالة واحدة | لا تعبئة تلقائية من الأمر الحالي؛ PARTIAL |

## الأزرار والحالات والأدلة المعروضة

| العنصر | التنفيذ الفعلي والسلطة | النجاح/الفشل | التصنيف/المشكلة |
|---|---|---|---|
| تأكيد العملية المحددة | POST route العملية، envelope وfields؛ busy يمنع الزر، register يتطلب workspace مؤكدًا | JSON أثر؛ reload جزئي للسجل/config/catalog/obligations/newConfigurationId؛ خطأ 422/409/403/غير مؤكد | PARTIAL؛ لا preview خاص بالأثر؛ لا يجدد الأمر تلقائيًا |
| بدء أمر جديد بعد مراجعة النتيجة | UUIDv4 جديد فقط؛ يعيد configuration_id من props في configuration وselectMarket | لا backend أو audit بهذا الزر | PARTIAL؛ لازم بين العمليات الناجحة؛ يبقي نتيجة العملية السابقة ظاهرة |
| تحديث سجل الأسواق والمراجعات | router.reload للـmarkets/configurations/catalogReferences | props جديدة، دون selectMarket أو watcher للprops | PARTIAL؛ لا يعالج expected_revision القديم تلقائيًا |
| عرض السياق الداخلي | GET contexts/{scope} | JSON سياق أو رسالة عامة | PARTIAL؛ UUID بدل اختيار سياق مفهوم |
| عرض الإيصال | GET receipts/{command} | receipt صف خام أو رسالة عامة | PARTIAL؛ إذن القراءة مستقل عن نجاح أمر الكتابة |
| إعادة محاولة التسليم | POST audit/{obligation}/recover؛ منحة Retry الدقيقة ومصادقة حديثة؛ custody مستقل ثم تسجيل حالته | HTTP200 قد يحمل failed؛ acknowledged يحمل acknowledgment_id؛ أخطاء عامة | PARTIAL؛ زر لا يتعطل لمن يملك read فقط؛ HTTP200 ليس نجاح تسليم |
| السوق متاح/غير متاح | من assignability.configuration_id وليس approval/launch | الإتاحة تؤثر على الجديد فقط | COMPLETE دلاليًا؛ القاعدة مكتوبة أعلى الصفحة |
| config.version | SQL max(version)+1 لكل سوق تحت قفل | رقم تاريخي readonly | COMPLETE؛ يختلف عن configuration UUID وassignability revision |
| عمليات الإدارة ضمن المنح المعروضة | availableActions من targets.action/target فقط | نص استرشادي | PARTIAL؛ K لا يربط version_id؛ E/D يختار أحدهما حسب حالة السوق رغم احتمال وجود كلا المنحتين؛ register لا يذكر هنا |
| تفاصيل الإصدار | details يعرض market/config/reference/catalog UUID وsource SHA | read-only من props الخاضعة لـRLS | مناسب كتفصيل؛ لا نسخة كاملة approval/recorded_at لكل مرجع مستقل |
| النتيجة / الإيصال | result ref من آخر كتابة أو استعلام أو recovery | JSON.stringify مع pre LTR | PARTIAL؛ صندوق واحد لثلاثة أنواع نتائج مختلفة؛ لا summary ولا ربط command/result/delivery |
| obligation command/state/attempts | rows من obligations join delivery تحت RLS داخل transaction GET | pending/failed/unknown/acknowledged، عدد المحاولات | PARTIAL؛ UUID/enums خام؛ لا target أو updated_at أو acknowledgment في القائمة |
| أخطاء الحقول | map لأخطاء 422 المعروفة، aria-invalid ورسائل تحت الحقول وقائمة عامة | 409 يتيح refresh؛403 يذكر grant/fresh login؛ فشل آخر يطلب التحقق من الإيصال | PARTIAL؛ SQL23503/23514 يتحول commercial عام، 422 رسائل الحقول ليست كلها مترجمة بهذا المكوّن |
| اللغة والتنقل والخروج | InternalLayout: locale، روابط، menu، logout | القشرة تتغير لغة/اتجاه؛ Commercial نفسه عربي ثابت | PARTIAL للترجمة؛ رابط Commercial ظاهر دائمًا داخل القشرة، backend يبقى بوابة القراءة/السلطة |

## مسارات العمل من البداية إلى النهاية

1. **السوق:** هدف UUIDv7 عليه C مسبقًا → اختيار الهدف واسم العرض → validation → MarketRegistry/CommercialContext → builderad_configure_commercial_market → فحص grant/session، fingerprint/prior → INSERT market وassignability revision1 → receipt+audit obligation+pending delivery في المعاملة المحلية نفسها → result JSON → تحديث السجل إذا توجد R. لا rename/delete عبر هذه العملية.
2. **Catalog:** سوق موجود وreference UUIDv7 ممنوح K → هوية/إصدار Catalog، مرجع الموافقة، source hash → ApprovedCatalogReference → builderad_record_commercial_catalog → فحص tuple السلطة والتكرار والقيود → حفظ immutable reference مع قفل producer عابر → receipt+obligation → commit مستقل قبل استهلاكه في config/assignability/register. لا حساب source hash ولا تحقق موافقة عبر جهة خارجية في هذا الكود.
3. **الإعدادات:** السوق → configuration UUIDv7 مولّد → اختيار reference إن وجد وعملة صريحة إن وجدت → builderad_create_market_configuration → قفل السوق، تحقق committed Catalog → version=max+1 → immutable config → receipt+obligation. يجوز حفظ غير مكتمل، لا يجوز تمكينه. تغيير المرجع أو العملة يتطلب إصدارًا جديدًا.
4. **الإتاحة:** السوق → اختيار config كامل أو null → snapshot expected_revision → builderad_set_market_assignability → E/D المناسبة، فحص prior، قفل revision/CAS، اكتمال tuple → تحديث pointer وrevision فقط → receipt+obligation. التعطيل لا يلغي assignment محفوظًا سابقًا ولا يغيّر التاريخ.
5. **التسجيل:** scope/workspace من المنحة → تأكيد اسم المستفيد عبر workspace-reference → اختيار سوق متاح → snapshot config/catalog/currency → builderad_register_commercial_scope → S+B+A الدقيقة، أقفال workspace/owner/lifecycle/membership، صلاحية المستفيد، assignability/config tuple ومصدر ملتزم → scope+binding+assignment+receipt+obligation ذريًا → CONTEXT_RECORDED مع agreement/amount UNKNOWN. فشل obligation المحلي يرد500 ويرجع كل الحقيقة التجارية. لا وظيفة rebinding أو إعادة تسعير في هذا الأمر.
6. **القراءة:** receipt UUID مع منحة قراءة مستقلة → RLS commercial_command_results؛ scope UUID مع منحة داخلية مستقلة → assignment/market/catalog projection. غياب القراءة لا يثبت فشل أمر سابق. أثر POST ليس الإيصال الكامل.
7. **التدقيق:** accepted fact ينتج obligation/delivery pending → retry بسلطة مستقلة → custody يقارن fingerprint ويحفظ acknowledgment بمعاملة مستقلة → origin يثبت acknowledged فقط إذا custody مطابق. failure/lost ack لا يلغي الحقيقة التجارية؛ retry يعيد ack نفسه عند القبول السابق. لا عامل تسليم دوري مثبت ضمن الملفات المفتوحة؛ المسار المرئي هو الاستعادة اليدوية.

## فجوات فعلية وأولويات المراجعة

1. **حالة النموذج/الإتاحة:** fields.configuration_id يبدأ بـnewConfigurationId حتى عندما العملية register. عند التحويل إلى assignability واختيار السوق، selectMarket يملأ revision فقط، فلا يختار config صالحًا. أثبت DOM أن selectedIndex=-1 وvalue الظاهر فارغ بينما Vue يحتفظ بمعرّف ليس خيارًا. إرسال قيمة Vue قد ينتج422؛ الفراغ الظاهر لا يثبت أن المشغّل اختار التعطيل. تغيير السوق في configuration/assignability كذلك لا يمسح مرجع/إصدار السوق السابق. الخلفية ترفض tuple الخاطئ، لكن الواجهة مربكة.
2. **مصدر المرجع المعتمد:** target.version_id يحمل reference المطلوب لـK ولا يستخدمه حقل catalog_reference_id؛ اليدوي قد يؤدي403 رغم وجود منحة صحيحة. لا توليد حر بدل ذلك لأن UUID جزء من authority contract.
3. **اختيار التسجيل قبل التحقق الكامل:** scopeTargets يختبر S فقط؛ السوق المتاح ليس بالضرورة config ممنوحًا بـA، وB قد تكون غائبة. كذلك منح S لا تضمن R لعرض markets. توجد وظيفة backend لا يمكن استعمالها من الاختيارات المتاحة عند عدم توفر قراءات السجل؛ لا تُحل بمنح ضمنية.
4. **دورة الأمر:** تغيير operation أو payload بعد نجاح لا يجدد command؛ العملية التالية تصبح409 حتى الضغط على «أمر جديد». هذا يحمي idempotency، لكن المسار اليومي يحتاج فصلًا واضحًا بين متابعة أمر سابق وبدء قرار جديد. يجب حفظ هوية الطلب الأصلي عند uncertain outcome وعدم الاشتقاق التلقائي من state متغير أثناء إعادة نفس الأمر.
5. **التحديث بعد التعارض:** reload props لا يحدّث expected_revision ولا readonly register snapshot. التوجيه الحالي «حدّث السجل» وحده غير كاف؛ إعادة اختيار السوق تصلحها. لا يجب إعادة كتابة payload لأمر معلوم النجاح/غير مؤكد دون مراجعة.
6. **معرّفات UUIDv7:** reference/config/scope/market لها قيود v7 في SQL، بينما Laravel يكتفي uuid/help عام. لا يوجد مولّد reference UI ولا تعليم لمصدر UUID المعتمد؛ config مولّد لكنه قابل للتغيير. command UUIDv4 صحيح ومقبول؛ catalog_identity لا يشترط v7.
7. **عرض Catalog:** props تتضمن recorded_at ومراجع منفصلة؛ الواجهة تعرض Catalog عبر configuration فقط. المرجع المسجل قبل إنشاء config لا يظهر في سجل مستقل؛ يصل عبر dropdown config وJSON النتيجة فقط.
8. **نتيجة وتسليم التدقيق:** لا فرق بصري واضح بين أثر تجاري، receipt، context، custody result. HTTP200 recovery مع state=failed يحتاج شرحًا واضحًا. نتيجة الكتابة لا تحمل command_id/obligation_id ولا حالة delivery؛ توجد الأدلة في مخازن منفصلة ويجب وصلها دون مساواتها.
9. **صلاحيات التشغيل:** availableActions لا يثبت tuple المرجع، ويعرض اتجاه إتاحة واحدًا. كل العملية options وكل أزرار retry ظاهرة دون تقييم capability دقيق؛ تمنع الخلفية غير المصرح به، لكنها توصل المستخدم لرفض متوقع.
10. **الترجمة والوصول:** الإنجليزية تغيّر القشرة فقط؛ Commercial title/body/messages عربي. يوجد main داخل main في Layout، landmark متداخل. الحقول تستعمل aria-describedby لمعرّفات error غير موجودة في الحالة السليمة؛ هذا خلل صغير. novalidate يجعل required/pattern غير حواجز متصفح في نموذج التنفيذ، مع بقاء validation الخادم.
11. **تكامل المستفيد:** يلزم مواءمة اختبارات Commercial مع Business prerequisite ثم إثبات السياق في Business/Sources؛ 302 الحالي لا يسمح بادعاء عرض مكتمل. لم يُعدل أي قرار Business لمعالجة الاختبارات في هذه المراجعة.

## قرارات المشغّل مقابل مشتقات النظام

**قرارات حقيقية:** السوق ضمن الأهداف المصرح بها؛ الاسم عند إنشاء الهوية؛ المصدر المعتمد/هوية وإصدار Catalog ومرجع موافقته؛ المرجع والعملة عند إنشاء config؛ أي config يُتاح أو قرار التعطيل؛ المستفيد/النطاق ضمن tuple منحة محددة؛ سبب العملية. اختيار currency لا يُشتق من الدولة أو لغة الصفحة أو workspace currency.

**يمكن اشتقاقها دون حذف الضمانات:** configuration UUID جديد من الخادم للإنشاء؛ reference UUID من tuple K بعد اختيار المنحة الدقيقة؛ scope/workspace من tuple S؛ config/catalog/currency للتسجيل من snapshot canonical assignability/config بعد اختيار السوق؛ expected_revision من snapshot السوق؛ command UUID ينشئه النظام لكل أمر جديد ويحفظه لنفس الطلب عند retry. هذا تحسين تقديم وليس إذنًا للكتابة أو ضمانًا لتوفر المنح.

**تبقى audit/read-only:** config.version، assignability revision (مع إرسالها كشرط تنافس)، command_id، request fingerprint، receipt actor/generation/reason/policy/timestamps، scope/binding/assignment IDs، source SHA بعد تسجيل reference، obligation fingerprint/envelope، state/attempts/acknowledgment. source SHA قبل التسجيل مصدره وثيقة معتمدة؛ لا canonical file مثبت هنا ليحسبه النظام بأمان. لا تجعل reason آليًا بلا سياسة تدقيق، ولا تجعل reference UUID جديدًا إذا grant تقيده مسبقًا.

## ترتيب معلومات مقترح لاحقًا — دون تنفيذ

1. الأسواق: اسم مفهوم، الإتاحة الحالية، الإصدار الحالي ومدى اكتماله، العمليات المتاحة بدقة.
2. السوق المحدد: مراجع Catalog المعتمدة مستقلة، ثم إصدارات الإعدادات وتاريخها. تمييز الهوية/الإصدار/revision.
3. إجراء واحد واضح: تسجيل هوية جديدة، توثيق مرجع، إنشاء إصدار، إتاحة/تعطيل، أو تسجيل مستفيد؛ عرض متطلبات السلطة والقراءة والأثر قبل التأكيد.
4. التسجيل: اسم المستفيد والنطاق المرخّص، السوق، مراجعة snapshot المرجع والعملة والإعداد؛ التفاصيل UUID مطوية.
5. نتيجة تشغيلية: ماذا حُفظ، command، رابط receipt/context، ثم حالة تسليم التدقيق مستقلة.
6. الأدلة والتشخيص: receipt/raw JSON/fingerprints/UUIDs/revision/actor/policy/timestamps/recovery في تفاصيل متقدمة؛ مراجعة دورة retry كما هي دون طمس فرق الأثر عن الإيصال وعن قبول التدقيق.

لا دليل على حاجة حقول أسعار أو اتفاقيات أو رفع منتجات في هذه الوظيفة. ما يسمى «إضافة كتالوج» هنا هو توثيق provenance. إدارة الصلاحيات وظيفة A-003 مستقلة؛ غياب presentation موثق، ومكان عرضها لاحقًا قرار Owner، لا إذن لتكرار الكاتب.

## الملفات والاختبارات المفتوحة

- resources/js/Pages/Internal/Commercial.vue — script وtemplate كاملان؛ النموذج والحالات وجميع routes المرئية.
- resources/js/Layouts/InternalLayout.vue؛ resources/css/app.css (admin selectors)؛ resources/js/Pages/Internal/Administration.vue (بحث commercial فقط).
- app/Http/Controllers/CommercialController.php؛ routes/web.php (مجموعة commercial ومسار workspaces.show).
- app/Commercial/MarketRegistry.php؛ ApprovedCatalogReference.php؛ RegisterCommercialScope.php؛ CommercialContext.php؛ CommercialMarketConfigurationVersion.php؛ CommercialEffects.php؛ CommercialAuditDelivery.php.
- app/Security/GrantAuthority.php (طرق commercial والمنح ومراجعها)؛ CommercialAuditCustody.php؛ config/commercial_authority.php.
- app/Http/Middleware/EnsureCurrentPrincipal.php؛ EnsureFreshAuthentication.php؛ EnsureBusinessContext.php؛ app/Http/Controllers/WorkspaceController.php (عرض السياق المرتبط).
- database/migrations/0001_01_01_000130_create_sa01_commercial_scope.php؛ database/sql/sa01.sql (الجداول، القيود، RLS، الأوامر، التكرار، committed Catalog والتدقيق).
- tests/Feature/Commercial/CommercialScopeTest.php: مراجعة تدفق الاختبارات/helpers و28 حالة منفذة في الجولة الكاملة؛ tests/TestCase.php؛ app/Security/DisposableTestDatabaseGuard.php.
- scripts/Test-BusinessFoundation.ps1؛ scripts/builderad.ps1؛ composer.json؛ package.json؛ phpunit.xml؛ vite.config.js.
- storage/framework/testing/sources-review-9008752e/{preview.ts,index.html,vite.config.mjs} — بوصفها نموذج fixture قائم، دون تعديلها.
- fixture جديد للمراجعة فقط: storage/framework/testing/commercial-review/{preview.ts,index.html,vite.config.mjs}.
- دليل التشغيل الكامل: storage/framework/testing/business-db-5bc46397-af96-4c4b-aaae-80d128db5f5d/{tests.log,results.xml}. بيانات قاعدة الاختبار حذفت بواسطة runner بعد توقفها.
- دليل إعادة اختبار التزامن الناجحة: storage/framework/testing/business-db-7a5cc983-f17b-4f3f-aadc-6ffe9127b5a1/{tests.log,results.xml}. توقفت قاعدة هذه الجولة ونظفت بياناتها أيضًا.

التوقف عند مراجعة Owner: التقرير لا يغيّر المنتج ولا يصرح بتنفيذ المقترحات.
