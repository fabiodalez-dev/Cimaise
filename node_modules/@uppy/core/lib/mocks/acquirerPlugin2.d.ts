import { vi } from 'vitest';
import UIPlugin from '../UIPlugin.js';
import type Uppy from '../Uppy.js';
type mock = ReturnType<typeof vi.fn>;
export default class TestSelector2 extends UIPlugin<any, any, any> {
    name: string;
    mocks: {
        run: mock;
        update: mock;
        uninstall: mock;
    };
    constructor(uppy: Uppy<any, any>, opts?: any);
    run(results: any): Promise<string>;
    update(state: any): void;
    uninstall(): void;
}
export {};
//# sourceMappingURL=acquirerPlugin2.d.ts.map