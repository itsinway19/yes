<?php

namespace App\MarketKnowledge;

use Illuminate\Http\Request;

final class CustomerDiscoveryConsumer
{
    public function inspect(Request $request, string $business, array $body): array
    {

        $finding = app(QualifiedKnowledgeReader::class)->read($request, $business, $body);

        $copy = fn (string $ar, string $en) => app()->getLocale() === 'ar' ? $ar : $en;

        if (($finding['validity']['status'] ?? 'unknown') !== 'current') {

            return ['assessment' => 'needs_review', 'message' => $copy('تحتاج المعرفة إلى مراجعة قبل البحث عن العملاء.', 'Review the knowledge before using it to explore customers.'),

                'next_step' => $copy('حدّث الأدلة أو راجع التصحيح مع صاحب الصلاحية.', 'Update the evidence or review its correction with an authorized operator.'),

                'limits' => $copy('لا يمكن اعتماد استنتاج حالي من هذه القراءة.', 'This read does not support a current conclusion.')];

        }

        if ($finding['method_ref'] === 'qualified_interest_change:1' && $finding['status'] === 'comparable' && isset($finding['values']['delta']) && (is_int($finding['values']['delta']) || is_float($finding['values']['delta'])) && is_finite((float) $finding['values']['delta']) && $finding['values']['delta'] > 0) {

            return ['assessment' => 'investigate_reach', 'message' => $copy('توجد زيادة مؤهلة في مؤشر الاهتمام تستحق فحص مسار الوصول.', 'A qualified rise in interest merits investigating a route to reach people.'),

                'next_step' => $copy('تحقق من القنوات والنطاق الجغرافي قبل اقتراح استهداف.', 'Qualify channels and geography before proposing targeting.'),

                'limits' => $copy('مؤشر الاهتمام ليس عدد عملاء ولا نية شراء مؤكدة.', 'Interest is neither a buyer count nor confirmed purchase intent.')];

        }

        return ['assessment' => 'needs_interest_evidence', 'message' => $copy('لا تكفي هذه المعرفة لتحديد أين يوجد العملاء.', 'This knowledge does not establish where customers can be reached.'),

            'next_step' => $copy('أضف مؤشرات اهتمام مؤهلة ومعرفة بالقنوات قبل تحديد مسار الوصول.', 'Add qualified interest signals and channel evidence before selecting a route to reach people.'),

            'limits' => $copy('معرفة العرض أو المنطقة لا تثبت وجود جمهور قابل للوصول.', 'Offer or geographic knowledge does not establish a reachable audience.')];

    }
}
