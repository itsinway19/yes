export type IdentityBinding = {
    lineage: string; epoch: number; boundary: 'advertiser' | 'internal'; credential: string;
    principal: number; lifecycle: number; context: string; generation: string;
};

export type IdentityMetadata = {
    receipt: string; transition: string; csrf: string; binding: IdentityBinding; leaseMilliseconds: number; renewalProtocol?: 0 | 1;
};
