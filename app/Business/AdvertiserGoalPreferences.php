<?php

namespace App\Business;

use App\Models\Workspace;
use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Workspace-owned advertiser preferences, never Intent or Object Purpose declarations. */
final class AdvertiserGoalPreferences
{
    public static function catalog(): array
    {
        $definitions = [
            'sales' => ['زيادة المبيعات والعملاء الفعليين', 'Increase sales and purchasing customers', 'زيادة المبيعات الفعلية والعملاء الذين يشترون.', 'Increase actual sales and customers who buy.', [
                'actual_sales' => ['زيادة عدد المبيعات الفعلية', 'Increase the number of actual sales'],
                'purchasing_customers' => ['جذب المزيد من العملاء الذين يشترون', 'Attract more customers who buy'],
                'sales_value' => ['زيادة قيمة المبيعات', 'Increase sales value'],
                'existing_customer_purchases' => ['زيادة مشتريات العملاء الحاليين', 'Increase purchases by existing customers'],
            ]],
            'customers' => ['جذب عملاء أكثر ملاءمة', 'Attract better-fit customers', 'الوصول إلى العملاء المناسبين لنشاطك.', 'Reach customers who are a better fit for your business.', [
                'serious_inquiries' => ['زيادة الاستفسارات الجادة', 'Increase serious inquiries'],
                'closer_to_purchase' => ['الوصول إلى العملاء الأقرب للشراء', 'Reach customers closer to purchasing'],
                'fewer_unsuitable_inquiries' => ['تقليل الاستفسارات غير المناسبة', 'Reduce unsuitable inquiries'],
                'inquiry_sales_conversion' => ['تحسين تحوّل الاستفسارات إلى مبيعات', 'Improve conversion of inquiries into sales'],
            ]],
            'growth' => ['اكتشاف فرص نمو جديدة', 'Discover new growth opportunities', 'البحث عن أسواق أو فئات عملاء جديدة للتوسع.', 'Explore new markets or customer groups for growth.', [
                'new_customer_groups' => ['اكتشاف فئات عملاء جديدة', 'Discover new customer groups'],
                'new_markets' => ['اكتشاف أسواق أو مناطق جديدة', 'Discover new markets or regions'],
                'new_needs' => ['اكتشاف احتياجات أو استخدامات جديدة', 'Discover new needs or uses'],
                'new_offerings' => ['اكتشاف فرص لمنتجات أو خدمات جديدة', 'Discover opportunities for new products or services'],
            ]],
            'efficiency' => ['تحسين العائد من الميزانية', 'Improve the return on the budget', 'تحقيق نتائج أفضل من الميزانية الإعلانية الحالية.', 'Get better results from the current advertising budget.', [
                'better_results' => ['تحقيق نتائج أفضل بالميزانية الحالية', 'Achieve better results with the current budget'],
                'less_unproductive_spend' => ['تقليل الإنفاق الإعلاني غير المجدي', 'Reduce unproductive advertising spend'],
                'profitable_ad_sales' => ['زيادة المبيعات المربحة من الإعلانات', 'Increase profitable sales from advertising'],
                'worthwhile_allocation' => ['معرفة أين تستحق الميزانية أن تُنفق', 'Understand where the budget is worth spending'],
            ]],
        ];
        $catalog = [];
        foreach ($definitions as $id => [$ar, $en, $descriptionAr, $descriptionEn, $results]) {
            $options = [];
            foreach ($results as $resultId => [$resultAr, $resultEn]) {
                $options[] = ['id' => $resultId, 'name_ar' => $resultAr, 'name_en' => $resultEn];
            }
            $catalog[] = ['id' => $id, 'name_ar' => $ar, 'name_en' => $en,
                'description_ar' => $descriptionAr, 'description_en' => $descriptionEn, 'results' => $options];
        }

        return $catalog;
    }

    public function projection(Workspace $workspace): array
    {
        $row = DB::table('advertiser_goal_preferences')->where('workspace_id', $workspace->id)->first();

        return ['value' => $row ? json_decode($row->value, true, flags: JSON_THROW_ON_ERROR)
            : ['goals' => [], 'priority' => null, 'results' => (object) []], 'version' => (int) ($row?->version ?? 0)];
    }

    public function write(Request $request, Workspace $workspace, array $value, int $version): array
    {
        return json_decode(DB::scalar('SELECT builderad_advertiser_goals_write(?,?,?::jsonb,?)', [
            $workspace->id, SessionAuthority::sessionKey($request), json_encode($value, JSON_THROW_ON_ERROR), $version,
        ]), true, flags: JSON_THROW_ON_ERROR);
    }
}
