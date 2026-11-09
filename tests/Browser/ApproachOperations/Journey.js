// Executed by the existing Playwright CLI. Private values are injected only locally.
async (page) => {
    const f = /* PRIVATE_FIXTURE */ null;
    const phase = /* JOURNEY_PHASE */ null;
    const checks = [];
    const check = (value, name) => { if (!value) throw new Error(name); checks.push(name); };
    const visible = async (locator, name) => { await locator.waitFor({ state: 'visible', timeout: 60000 }); check(true, name); };
    const go = async (p, path) => p.goto(f.base + path, { waitUntil: 'domcontentloaded', timeout: 60000 });
    const button = (p, name) => p.getByRole('button', { name, exact: true });
    const login = async (name) => {
        const context = await page.context().browser().newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
        const p = await context.newPage();
        await p.bringToFront();
        await go(p, '/institution/login');
        const g = p.getByRole('group', { name: 'تسجيل الدخول', exact: true });
        const a = f.accounts[name];
        await g.getByRole('textbox', { name: 'معرّف المؤسسة', exact: true }).fill(a.organization);
        await g.getByRole('textbox', { name: 'البريد المسجل', exact: true }).fill(a.email);
        await g.getByRole('textbox', { name: 'كلمة المرور', exact: true }).fill(a.password);
        await button(g, 'تسجيل الدخول').click({ noWaitAfter: true });
        await visible(p.getByRole('heading', { name: 'الحساب المؤسسي', exact: true }), name + ' actual login');
        return p;
    };
    const layout = async (p, name) => {
        await p.bringToFront();
        await p.waitForLoadState('load', { timeout: 60000 });
        await p.evaluate(() => document.fonts.ready);
        check(await p.locator('html').getAttribute('dir') === 'rtl', name + ' RTL');
        check(await p.locator('.fi-btn').count() > 0, name + ' native buttons');
        for (const width of [1280, 375]) {
            await p.setViewportSize({ width, height: 900 });
            await p.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            check(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ' width ' + width);
            await p.screenshot({ path: f.artifacts + '/' + name + '-' + width + '.png', fullPage: true });
        }
        await p.setViewportSize({ width: 1280, height: 900 });
    };
    if (phase === 'workflow') {
        const q = await login('quality');
        await layout(q, 'account');
        await q.getByRole('link', { name: 'فتح مساحة المؤسسة', exact: true }).click({ noWaitAfter: true });
        await visible(q.getByRole('heading', { name: 'مساحة المؤسسة', exact: true }), 'actual dashboard');
        await layout(q, 'dashboard');
        await q.getByRole('link', { name: 'فتح بنك المنهجيات', exact: true }).click({ noWaitAfter: true });
        await visible(q.getByRole('heading', { name: 'بنك المنهجيات', exact: true, level: 1 }), 'actual bank');
        check(!q.url().includes('/admin/ui-preview'), 'operational Filament route');
        check(!(await q.locator('body').innerText()).includes('غير مسندة ولا تظهر'), 'unassigned resource absent');
        await button(q, 'إنشاء منهجية').click();
        const create = q.getByRole('dialog', { name: 'إنشاء منهجية', exact: true });
        // Filament's portal dialog wrapper has zero height; assert its rendered controls.
        await visible(create.getByRole('textbox', { name: /^اسم المنهجية/ }), 'native create modal');
        for (const [name, value] of [['اسم المنهجية', 'منهجية الرحلة الفعلية'], ['الوصف', 'وصف من المتصفح'], ['الغاية', 'غاية من المتصفح'], ['النطاق', 'نطاق من المتصفح']]) {
            await create.getByRole('textbox', { name: new RegExp('^' + name) }).fill(value);
        }
        await create.getByRole('button', { name: /^الوحدة التنظيمية المالكة/ }).click();
        await create.getByRole('option', { name: 'وحدة اختبار المنهجيات', exact: true }).click();
        await button(create, 'إنشاء منهجية').click();
        await create.getByRole('textbox', { name: /^اسم المنهجية/ }).waitFor({ state: 'hidden', timeout: 60000 });
        const row = q.getByRole('link').filter({ hasText: 'منهجية الرحلة الفعلية' }).first();
        await visible(row, 'created resource in scoped list');
        const href = await row.getAttribute('href');
        const resource = href.split('/').pop();
        check(/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(resource), 'public resource identifier');
        await row.click({ noWaitAfter: true });
        await visible(button(q, 'تعديل البيانات التعريفية'), 'assigned quality metadata action');
        check(await button(q, 'حفظ إصدار مسودة').count() === 0, 'quality has no implicit draft grant');
        check(await button(q, 'نقل الملكية').count() === 0, 'no transfer grant');
        await button(q, 'تعديل البيانات التعريفية').click();
        const edit = q.getByRole('dialog');
        await visible(edit.getByRole('textbox', { name: /^اسم المنهجية/ }), 'native edit modal');
        await edit.getByRole('textbox', { name: /^اسم المنهجية/ }).fill('منهجية الرحلة المحدثة');
        await button(edit, 'حفظ البيانات').click();
        await edit.getByRole('textbox', { name: /^اسم المنهجية/ }).waitFor({ state: 'hidden', timeout: 60000 });
        await go(q, '/institution/workspace/approaches/' + resource);
        await visible(q.getByText('منهجية الرحلة المحدثة', { exact: true }), 'metadata survives reload');
        const o = await login('contributor');
        await o.getByRole('link', { name: 'فتح مساحة المؤسسة', exact: true }).click({ noWaitAfter: true });
        await visible(o.getByRole('heading', { name: 'مساحة المؤسسة', exact: true }), 'contributor dashboard');
        await go(o, '/institution/workspace/approaches');
        await visible(o.getByRole('link').filter({ hasText: 'منهجية الرحلة المحدثة' }).first(), 'assigned contributor list');
        check(await button(o, 'إنشاء منهجية').count() === 0, 'contributor cannot create');
        await go(o, '/institution/workspace/approaches/' + resource);
        await visible(button(o, 'حفظ إصدار مسودة'), 'explicit contributor draft grant');
        check(await button(o, 'تعديل البيانات التعريفية').count() === 0, 'contributor cannot edit metadata');
        for (const body of ['المسودة الأولى من المتصفح', 'المسودة الثانية من المتصفح']) {
            await button(o, 'حفظ إصدار مسودة').click();
            const draft = o.getByRole('dialog');
            await visible(draft.getByRole('textbox', { name: /^محتوى المسودة/ }), 'native draft modal');
            await draft.getByRole('textbox', { name: /^محتوى المسودة/ }).fill(body);
            await button(draft, 'حفظ إصدار مسودة').click();
            await draft.getByRole('textbox', { name: /^محتوى المسودة/ }).waitFor({ state: 'hidden', timeout: 60000 });
            await visible(o.getByText(body, { exact: true }), 'draft persisted in history');
        }
        await go(o, '/institution/workspace/approaches/' + resource);
        await visible(o.getByText('المسودة الأولى من المتصفح', { exact: true }), 'first revision retained');
        await visible(o.getByText('المسودة الثانية من المتصفح', { exact: true }), 'second revision retained');
        await layout(o, 'draft-history');
        await o.getByLabel('نوع السجل', { exact: true }).selectOption('audit');
        await visible(o.getByText('منهجية الرحلة الفعلية', { exact: false }), 'metadata audit before state');
        await layout(o, 'audit-history');
        for (const id of [f.hidden, f.foreign]) {
            const response = await o.context().request.get(f.base + '/institution/workspace/approaches/' + id);
            check([403, 404].includes(response.status()), 'unassigned/cross-tenant detail denied');
        }
        for (const name of ['unassigned', 'issuer', 'foreign']) {
            const denied = await login(name);
            check(await denied.getByRole('link', { name: 'فتح مساحة المؤسسة', exact: true }).count() === 0, name + ' account has no workspace link');
            check((await denied.context().request.get(f.base + '/institution/workspace')).status() === 403, name + ' workspace forbidden');
            await denied.context().close();
        }
        const csrf = await q.context().request.post(f.base + '/institution/' + f.accounts.quality.organization + '/logout', { form: {}, headers: { Origin: 'https://invalid.example', 'Sec-Fetch-Site': 'cross-site' } });
        check(csrf.status() === 419, 'actual HTTP CSRF enforced (status ' + csrf.status() + ')');
        page.__j03 = { q, o, resource };
        return { passed: true, resource, checks };
    }
    const { q, o, resource } = page.__j03;
    if (phase === 'revoked') {
        check((await o.context().request.get(f.base + '/institution/workspace')).status() === 403, 'existing contributor session denied after revoke');
        check((await o.context().request.get(f.base + '/institution/workspace/approaches/' + resource)).status() === 403, 'revoked detail forbidden');
        check((await q.context().request.get(f.base + '/institution/workspace/approaches/' + resource)).status() === 200, 'quality remains scoped after contributor revoke');
    } else if (phase === 'expired') {
        check((await q.context().request.get(f.base + '/institution/workspace')).status() === 403, 'existing quality session denied after expiry simulation');
        for (const p of [q, o]) {
            const name = p === q ? 'quality' : 'contributor';
            await go(p, '/institution/' + f.accounts[name].organization + '/account');
            check(await p.getByRole('link', { name: 'فتح مساحة المؤسسة', exact: true }).count() === 0, name + ' account loses workspace link');
            await button(p, 'تسجيل الخروج').click({ noWaitAfter: true });
            await visible(p.getByRole('heading', { name: 'دخول الحساب المؤسسي', exact: true }), name + ' actual logout');
            const response = await p.context().request.get(f.base + '/institution/workspace', { maxRedirects: 0 });
            check(response.status() === 302, name + ' guest denied');
            await p.context().close();
        }
    } else throw new Error('Unknown explicit journey phase');
    return { passed: true, checks };
}
