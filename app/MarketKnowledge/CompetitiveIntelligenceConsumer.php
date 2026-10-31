<?php

namespace App\MarketKnowledge;

use Illuminate\Http\Request;

final class CompetitiveIntelligenceConsumer
{
    public function inspect(Request $request, string $business, array $body): array
    {

        $finding = app(QualifiedKnowledgeReader::class)->read($request, $business, $body);

        $copy = fn (string $ar, string $en) => app()->getLocale() === 'ar' ? $ar : $en;

        if (($finding['validity']['status'] ?? 'unknown') !== 'current') {

            return ['assessment' => 'needs_review', 'message' => $copy('تحتاج المقارنة إلى مراجعة قبل الحكم على المنافسة.', 'Review the comparison before drawing a competitive conclusion.'),

                'next_step' => $copy('راجع الأدلة المتغيرة ثم أعد المقارنة بحقوق مؤهلة.', 'Review the changed evidence and rerun the comparison with qualified rights.'),

                'limits' => $copy('النتيجة المحفوظة ليست بالضرورة صالحة الآن.', 'A retained result is not necessarily current.')];

        }

        if ($finding['method_ref'] === 'offer_equivalence:1' && $finding['status'] === 'equivalent') {

            return ['assessment' => 'terms_comparable', 'message' => $copy('تطابقت هوية المنتج وشروط الخدمة في المقارنة المؤهلة.', 'Product identity and service terms match in the qualified comparison.'),

                'next_step' => $copy('قارن أسعار عروض متكافئة وتحقق من سياق المنافس.', 'Compare prices of equivalent offers and qualify competitor context.'),

                'limits' => $copy('هذا لا يثبت تفوقًا تجاريًا أو مبيعات فعلية.', 'This establishes neither commercial advantage nor completed sales.')];

        }

        if ($finding['method_ref'] === 'qualified_price_observation:1' && $finding['status'] === 'observed') {

            return ['assessment' => 'needs_comparable_offer', 'message' => $copy('ملاحظة سعر واحدة لا تكفي للحكم على المنافسة.', 'One price observation is insufficient for a competitive conclusion.'),

                'next_step' => $copy('أضف عرضًا آخر مؤهل الهوية والشروط قبل مقارنة الأسعار.', 'Add a second offer with qualified identity and terms before comparing prices.'),

                'limits' => $copy('السعر المنشور لا يثبت بيعًا أو ربحية.', 'An observed price establishes neither a sale nor profitability.')];

        }

        return ['assessment' => 'needs_comparable_offers', 'message' => $copy('لا تتوفر مقارنة عروض مؤهلة تكفي للحكم على المنافسة.', 'A qualified offer comparison is required for a competitive conclusion.'),

            'next_step' => $copy('استكمل هوية المنتجات وشروط العروض وحقوق استعمالها.', 'Qualify product identities, offer terms and their use rights.'),

            'limits' => $copy('تغير الاهتمام وحده لا يثبت ميزة على منافس.', 'An interest change alone does not establish a competitive advantage.')];

    }
}
