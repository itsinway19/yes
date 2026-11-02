/** BF03-I01: own private assertions; never analysis or independent verification. */
export function inquiryEvidenceContracts({ref,text,uuid,object,list,nullable,json,response,security,errors}) {
 const integer={type:'integer',minimum:0,maximum:1000000000000};
 const positive={type:'integer',minimum:1};
 const grant={type:'string',pattern:'^[1-9][0-9]{0,17}$'};
 const selection=object({realm_kind:{const:'direct'},realm_workspace_public_id:nullable(uuid),requirements:{...list(object({requirement_kind:{const:'business'},leaf_grant_id:grant})),minItems:1,maxItems:1}});
 const value=object({
  cohort:object({channel_kind:{enum:['form','call','message','crm','other']},definition_version:positive,country_code:nullable({type:'string',pattern:'^[A-Z]{2}$'}),population:{const:'recorded_inquiries'},counting_policy:{const:'distinct_inquiries'}}),
  observation:object({period_start:text,period_end:text,as_of:text,timezone:text}),
  cost:nullable(object({amount:{type:'string',pattern:'^(0|[1-9][0-9]{0,11})([.][0-9]{1,8})?$'},currency:{type:'string',pattern:'^[A-Z]{3}$'},precision:{type:'integer',minimum:0,maximum:8},cost_basis:{enum:['advertising_spend','declared_cohort_cost']},allocation_basis:{enum:['whole_cohort','declared_attribution']}})),
  inquiries:nullable(object({count:nullable(integer),coverage:{enum:['complete','partial','unknown']}})),
  acceptance:nullable(object({count:nullable(integer),pending_count:nullable(integer),criterion_code:{enum:['operator_defined','within_service_area','response_ready']},criterion_version:positive,criterion_definition:nullable({type:'string',minLength:8,maxLength:500}),cutoff:text,maturity:{enum:['mature','pending','unknown']},coverage:{enum:['complete','partial','unknown']}})),
 });
 const historicalValue=structuredClone(value);
 historicalValue.properties.acceptance.anyOf.find(s=>s.type==='object').required=historicalValue.properties.acceptance.anyOf.find(s=>s.type==='object').required.filter(k=>k!=='criterion_definition');
 const action={enum:['business.inquiry_evidence.record','business.inquiry_evidence.correct','business.inquiry_evidence.read']};
 const grants=object(Object.fromEntries(action.enum.map(a=>[a,grant])),[]);
 const versions=object({},[],true);
 const receipt=object({command_id:uuid,declaration_id:uuid,revision:positive,state:{enum:['source_asserted','withdrawn']},assurance:{const:'SOURCE_ASSERTED'},independently_verified:{const:false},versions});
 const item=object({declaration_id:uuid,revision:positive,state:{enum:['source_asserted','withdrawn']},reason:{enum:['initial','correction','withdrawal']},value:nullable(ref('InquiryHistoricalDeclarationValue')),recorded_at:text,original_issuer:uuid,correcting_actor:uuid});
 const read=object({business:uuid,mode:{const:'own_private_statement'},assurance:{const:'SOURCE_ASSERTED'},independently_verified:{const:false},items:{...list(item),maxItems:25},more:{type:'boolean'},next_declaration:nullable(uuid),next_revision:nullable(positive),collection_limit:{const:25}});
 const eligibility=object({business:uuid,basis_qualification:uuid,basis_revision:positive,policy:{const:'inquiry_evidence_custody_v1'},policy_version:{const:1},mode:{const:'own_private_statement'},grants});
 const base='/api/businesses/{business}/inquiry-evidence';
 const bp={in:'path',name:'business',required:true,schema:uuid},dp={in:'path',name:'declaration',required:true,schema:uuid};
 const access={in:'query',name:'access',required:true,schema:grant};
 const result=x=>object({data:x});
 const responses=x=>({200:response(result(x)),...errors,...Object.fromEntries([403,409,422].map(n=>[n,response({anyOf:[object({error:text}),ref('Error'),ref('ValidationError')]})]))});
 const post=(operationId,extra,output,decl=false)=>({operationId,security,parameters:[bp,...(decl?[dp]:[])],requestBody:{required:true,...json(object({command_id:uuid,authority_selection:ref('InquiryAuthoritySelection'),...extra}))},responses:responses(output)});
 const get=(operationId,output,extra=[],decl=false)=>({operationId,security,parameters:[bp,...(decl?[dp]:[]),access,...extra],responses:responses(output)});
 return {schemas:{InquiryAuthoritySelection:selection,InquiryDeclarationValue:value,InquiryHistoricalDeclarationValue:historicalValue,InquiryEvidenceEligibility:eligibility,InquiryEvidenceRead:read,InquiryEvidenceReceipt:receipt},paths:{
  [base+'/eligibility']:{get:get('readInquiryEvidenceEligibility',eligibility)},
  [base+'/enrollment']:{post:post('enrollInquiryEvidence',{basis_revision:positive,confirm:{const:true}},object({command_id:uuid,policy:{const:'inquiry_evidence_custody_v1'},qualification:uuid,grants,versions}))},
  [base+'/rights']:{post:post('revokeInquiryEvidenceRight',{action},object({command_id:uuid,revoked_action:action,versions}))},
  [base]:{get:get('readInquiryEvidence',read,[{in:'query',name:'cursor',schema:uuid}]),post:post('recordInquiryEvidence',{value:ref('InquiryDeclarationValue')},receipt)},
  [base+'/{declaration}/revisions']:{post:post('reviseInquiryEvidence',{expected_revision:positive,effect:{enum:['correct','withdraw']},value:nullable(ref('InquiryDeclarationValue'))},receipt,true)},
  [base+'/{declaration}/history']:{get:get('readInquiryEvidenceHistory',read,[{in:'query',name:'after',schema:integer}],true)},
 }};
}
