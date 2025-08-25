import type { Body, Meta, PluginOpts, Uppy } from '@uppy/core';
import type { CompanionClientProvider, RequestOptions } from '@uppy/utils/lib/CompanionClientProvider';
import type { CompanionPluginOptions } from './index.js';
import RequestClient from './RequestClient.js';
export interface Opts extends PluginOpts, CompanionPluginOptions {
    pluginId: string;
    name?: string;
    supportsRefreshToken?: boolean;
    provider: string;
}
export default class Provider<M extends Meta, B extends Body> extends RequestClient<M, B> implements CompanionClientProvider {
    #private;
    provider: string;
    id: string;
    name: string;
    pluginId: string;
    tokenKey: string;
    companionKeysParams?: Record<string, string>;
    preAuthToken: string | null;
    supportsRefreshToken: boolean;
    constructor(uppy: Uppy<M, B>, opts: Opts);
    headers(): Promise<Record<string, string>>;
    onReceiveResponse(response: Response): Response;
    setAuthToken(token: string): Promise<void>;
    protected removeAuthToken(): Promise<void>;
    /**
     * Ensure we have a preauth token if necessary. Attempts to fetch one if we don't,
     * or rejects if loading one fails.
     */
    ensurePreAuth(): Promise<void>;
    authQuery(data: unknown): Record<string, string>;
    authUrl({ authFormData, query, }: {
        authFormData: unknown;
        query: Record<string, string>;
    }): string;
    protected loginSimpleAuth({ uppyVersions, authFormData, signal, }: {
        uppyVersions: string;
        authFormData: unknown;
        signal: AbortSignal;
    }): Promise<void>;
    protected loginOAuth({ uppyVersions, authFormData, signal, }: {
        uppyVersions: string;
        authFormData: unknown;
        signal: AbortSignal;
    }): Promise<void>;
    login({ uppyVersions, authFormData, signal, }: {
        uppyVersions: string;
        authFormData: unknown;
        signal: AbortSignal;
    }): Promise<void>;
    refreshTokenUrl(): string;
    fileUrl(id: string): string;
    protected request<ResBody>(...args: Parameters<RequestClient<M, B>['request']>): Promise<ResBody>;
    fetchPreAuthToken(): Promise<void>;
    list<ResBody>(directory: string | null, options: RequestOptions): Promise<ResBody>;
    logout<ResBody>(options?: RequestOptions): Promise<ResBody>;
}
//# sourceMappingURL=Provider.d.ts.map