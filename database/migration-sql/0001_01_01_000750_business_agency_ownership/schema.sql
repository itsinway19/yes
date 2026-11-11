-- Extend the existing qualification owner; no second membership or ACL store.
ALTER TABLE public.authority_qualification_receipts
 ADD COLUMN agency_business_id bigint REFERENCES public.businesses(id) ON DELETE RESTRICT,
 ADD COLUMN agency_membership_grant_id bigint REFERENCES public.security_action_grants(id) ON DELETE RESTRICT,
 ADD CONSTRAINT agency_qualification_shape CHECK(
  (agency_business_id IS NULL AND agency_membership_grant_id IS NULL
   AND policy_id NOT IN('business_agency_mandate_v1','business_agency_assignment_v1')) OR
  (agency_business_id IS NOT NULL AND agency_membership_grant_id IS NOT NULL
   AND policy_id IN('business_agency_mandate_v1','business_agency_assignment_v1')
   AND policy_version=1 AND target_kind='business' AND issuer_kind='existing_grant'
   AND issuer_grant_id IS NOT NULL AND valid_until IS NOT NULL));
CREATE INDEX agency_qualification_basis ON public.authority_qualification_receipts(agency_business_id,agency_membership_grant_id)
 WHERE agency_business_id IS NOT NULL;
