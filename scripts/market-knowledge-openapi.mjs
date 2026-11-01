/** Fixed implemented D3 foundation ingresses; no arbitrary producer/method registration. */
export function marketKnowledgeContracts({ref,text,uuid,object,list,nullable,json,response,security,errors}) {
    const selection = ref('BusinessAuthoritySelection'), material = ref('BusinessMaterialReference');
    const purpose = {type:'string',minLength:1,maxLength:128};
    const dependency = object({input:material,data_use_scope_id:uuid,policy_id:text,policy_version:{type:'integer',minimum:1}});
    const data = value => object({data:value});
    const scoped = {in:'path',name:'business',required:true,schema:uuid};
    const post = (operationId,body,result,business=false) => ({operationId,security,parameters:business?[scoped]:[],
        description:'Bounded foundation interface with current signed authority and source/field/purpose rights. No arbitrary finding or execution ticket is accepted from HTTP. This does not activate a complete commercial user journey.',
        requestBody:{required:true,...json(body)},responses:{200:response(data(result)),...errors,
        ...Object.fromEntries([403,409,422].map(code=>[code,response({anyOf:[object({error:text}),ref('Error'),ref('ValidationError')]})]))}});
    const capture = object({command_id:uuid,question:{enum:['compare_offers','compare_interest','record_price','record_geography']},material_refs:{...list(material),minItems:1,maxItems:2},purpose,authority_selection:selection});
    const captured = object({run_ref:uuid,state:{const:'prepared'},method_ref:text,versions:object({capture:{const:1}})});
    const publication = object({command_id:uuid,run_ref:uuid,output_scope_id:uuid,process_selection:selection,publication_selection:selection});
    const published = object({artifact_ref:material,manifest_id:uuid,state:{const:'published'},versions:object({publication:{const:1}})});
    const privateCapture = object({command_id:uuid,processing_run_ref:uuid,read_command_id:uuid,output_scope_id:uuid,authority_selection:selection,context_selection:nullable(selection),continuation_selection:selection});
    const privateCaptured = object({artifact_ref:material,dependencies:{...list(dependency),minItems:1,maxItems:64},versions:object({capture:{const:1}})});
    const consume = object({command_id:uuid,material_ref:material,purpose,authority_selection:selection});
    const assessment = object({assessment:{enum:['needs_review','investigate_reach','needs_interest_evidence','terms_comparable','needs_comparable_offer','needs_comparable_offers']},message:text,next_step:text,limits:text});
    const base='/api/businesses/{business}';
    return {schemas:{MarketKnowledgeCaptureCommand:capture,MarketKnowledgeCaptureResult:data(captured),MarketKnowledgePublicationCommand:publication,MarketKnowledgePublicationResult:data(published),MarketBusinessFindingCaptureCommand:privateCapture,MarketBusinessFindingCaptureResult:data(privateCaptured),KnowledgeConsumerCommand:consume,KnowledgeConsumerResult:data(assessment)},paths:{
        '/internal/market-knowledge/capture':{post:post('captureQualifiedSharedKnowledge',capture,captured)},
        '/internal/market-knowledge/publish':{post:post('publishTrustedSharedKnowledge',publication,published)},
        [`${base}/market-findings/capture`]:{post:post('captureTrustedPrivateFinding',privateCapture,privateCaptured,true)},
        [`${base}/knowledge/customer-discovery`]:{post:post('inspectKnowledgeForCustomerDiscovery',consume,assessment,true)},
        [`${base}/knowledge/competition`]:{post:post('inspectKnowledgeForCompetition',consume,assessment,true)},
    }};
}
