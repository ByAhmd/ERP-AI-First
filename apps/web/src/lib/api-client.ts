/**
 * Centralized API client for the ERP AI frontend.
 * - Automatically attaches x-tenant-id from localStorage
 * - Handles 401 by attempting silent token refresh
 * - Redirects to /login if session is fully expired
 */
export class ApiClient {
  static getActiveTenantId(): string | null {
    if (typeof window === 'undefined') return null;
    return localStorage.getItem('activeTenantId');
  }

  static setActiveTenantId(id: string): void {
    if (typeof window === 'undefined') return;
    localStorage.setItem('activeTenantId', id);
  }

  static clearActiveTenantId(): void {
    if (typeof window === 'undefined') return;
    localStorage.removeItem('activeTenantId');
  }

  static async fetch<T>(endpoint: string, options?: RequestInit): Promise<T> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      ...(options?.headers as Record<string, string>),
    };

    // Auto-attach the active tenant ID header
    const tenantId = ApiClient.getActiveTenantId();
    if (tenantId) {
      headers['x-tenant-id'] = tenantId;
    }

    const res = await fetch(`/api/v1${endpoint}`, {
      ...options,
      headers,
    });

    // If 401, try to refresh the access token and retry once
    if (res.status === 401 && endpoint !== '/auth/refresh' && endpoint !== '/auth/login') {
      try {
        const refreshRes = await fetch('/api/v1/auth/refresh', { method: 'POST' });
        if (refreshRes.ok) {
          // Token refreshed — retry the original request with updated headers
          const retryRes = await fetch(`/api/v1${endpoint}`, {
            ...options,
            headers,
          });

          if (!retryRes.ok) {
            if (retryRes.status === 401) {
              throw new Error('Session expired. Please log in again.');
            }
            // For other errors (403, 500, etc) do NOT throw an Error inside this block
            // wait, if we throw an Error here, the catch block below will intercept it.
            // Let's create a custom error to differentiate.
            const errData = await retryRes.json().catch(() => ({}));
            const apiError = new Error(errData.message || 'API Request failed');
            apiError.name = 'ApiError';
            throw apiError;
          }
          if (retryRes.status === 204) return {} as T;
          return retryRes.json();
        } else {
          // Refresh failed — session is fully expired, redirect to login
          ApiClient.clearActiveTenantId();
          if (typeof window !== 'undefined') window.location.href = '/login';
          throw new Error('Session expired. Please log in again.');
        }
      } catch (err: any) {
        if (err.message === 'Session expired. Please log in again.') {
          ApiClient.clearActiveTenantId();
          if (typeof window !== 'undefined') window.location.href = '/login';
          throw err;
        }
        // If it's a normal API error (like 403 or 500) that happened after a successful token refresh, just throw it!
        throw err;
      }
    }

    if (!res.ok) {
      let errorMsg = 'API Request failed';
      try {
        const errorData = await res.json();
        if (errorData.message) {
          if (Array.isArray(errorData.message)) {
            errorMsg = errorData.message.join(', ');
          } else if (typeof errorData.message === 'object') {
            errorMsg = JSON.stringify(errorData.message);
          } else {
            errorMsg = String(errorData.message);
          }
        }
      } catch {
        // Non-JSON error response
      }
      throw new Error(errorMsg);
    }

    if (res.status === 204) {
      return {} as T;
    }

    return res.json();
  }

  static async get<T>(endpoint: string, headers?: HeadersInit): Promise<T> {
    return this.fetch<T>(endpoint, { method: 'GET', headers });
  }

  static async post<T>(endpoint: string, data?: any, options?: RequestInit): Promise<T> {
    return this.fetch<T>(endpoint, {
      ...options,
      method: 'POST',
      body: data ? JSON.stringify(data) : undefined,
    });
  }

  static async put<T>(endpoint: string, data?: any, options?: RequestInit): Promise<T> {
    return this.fetch<T>(endpoint, {
      ...options,
      method: 'PUT',
      body: data ? JSON.stringify(data) : undefined,
    });
  }

  static async patch<T>(endpoint: string, data?: any, options?: RequestInit): Promise<T> {
    return this.fetch<T>(endpoint, {
      ...options,
      method: 'PATCH',
      body: data ? JSON.stringify(data) : undefined,
    });
  }

  static async delete<T>(endpoint: string, options?: RequestInit): Promise<T> {
    return this.fetch<T>(endpoint, {
      ...options,
      method: 'DELETE',
    });
  }
}
