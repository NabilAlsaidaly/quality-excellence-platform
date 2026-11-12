// Invoked by the owner-private runner with locally read credentials; never embed them here.
export async function documentJourney(browser, fixture, options) {
    const checks = [];
    const failedDownloads = [];
    const contexts = [];
    const check = (value, name) => { if (!value) throw new Error(name); checks.push(name); options.progress?.(name); };
    const login = async name => {
        const context = await browser.newContext({viewport:{width:1280,height:900},reducedMotion:'reduce',acceptDownloads:true});
        contexts.push(context);
        const page = await context.newPage();
        page.setDefaultTimeout(60000);
        await page.goto(options.base+'/institution/login',{waitUntil:'domcontentloaded'});
        const group = page.getByRole('group',{name:'تسجيل الدخول',exact:true});
        for (const [label,key] of [['معرّف المؤسسة','organization'],['البريد المسجل','email'],['كلمة المرور','password']]) {
            await group.getByRole('textbox',{name:label,exact:true}).fill(fixture.accounts[name][key]);
        }
        await group.getByRole('button',{name:'تسجيل الدخول',exact:true}).click();
        await page.getByRole('heading',{name:'الحساب المؤسسي',exact:true}).waitFor();
        check(true,name+' real independent institutional login');
        return page;
    };
    const go = async (page,path) => {
        const response=await page.goto(options.base+path,{waitUntil:'domcontentloaded',timeout:120000});
        check(response.status()===200,'actual protected screen responds');
    };
    const button=(p,name)=>p.getByRole('button',{name,exact:true});
    const submit=async(page,control)=>{const pending=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===fixture.livewire_update&&r.request().postDataJSON()?.components?.some(c=>c.calls?.some(call=>call.method==='callMountedAction')));await control.click();const response=await pending;check(response.status()===200,'exact native decision Action response acknowledged');await response.finished();};
    const upload = async (page,action,path,metadata) => {
        await button(page,action).click();
        const dialog=page.getByRole('dialog').last();
        if(metadata){await dialog.getByRole('textbox',{name:/^عنوان الوثيقة/}).fill(metadata.title);await dialog.getByRole('textbox',{name:/^الوصف/}).fill(metadata.description);}
        const uploaded=page.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===fixture.livewire_upload);
        await dialog.locator('input[type=file]').setInputFiles(path);
        check((await uploaded).status()===200,'native authorized multipart accepted');
        await dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]').waitFor();
        const persisted=page.waitForResponse(response=>{
            if(response.request().method()!=='POST'||new URL(response.url()).pathname!==fixture.livewire_update)return false;
            return response.request().postDataJSON()?.components?.some(component=>component.calls?.some(call=>call.method==='callMountedAction'));
        });
        await button(dialog,'حفظ النسخة').click();
        const saved=await persisted;
        check(saved.status()===200,'exact native save Action response acknowledged');
        await saved.finished();
        await dialog.waitFor({state:'hidden'});
        await page.getByRole('heading',{name:'مساحة الوثيقة',exact:true}).waitFor();
        check(true,'native file form persisted and opened exact document');
        return new URL(page.url()).pathname.split('/').at(-1);
    };
    const release = async (page,document) => {
        await go(page,'/institution/mvp-workspace/documents/'+document);
        await button(page,'قرار الإتاحة المحلية').first().click();
        const dialog=page.getByRole('dialog').last();
        await dialog.getByRole('combobox',{name:/^القرار/}).selectOption('available');
        await dialog.getByRole('textbox',{name:/^سبب القرار/}).fill('ملف اصطناعي معلوم المصدر للعرض المحلي فقط؛ غير مفحوص أمنيًا.');
        await submit(page,dialog.locator('button[type=submit]'));
        await dialog.waitFor({state:'hidden'});
        await page.getByText('متاحة للعرض المحلي — غير مفحوصة أمنيًا',{exact:true}).first().waitFor();
        check(true,'independent truthful local availability decision persisted');
    };
    const download = async (page,filename,document,version) => {
        const pending=page.waitForEvent('download').catch(error=>error);
        const expected='/institution/local-document-versions/'+document+'/'+version+'/download';
        const link=page.getByRole('link',{name:'تنزيل خاص — غير مفحوص',exact:true}).and(page.locator('a[href="'+expected+'"]'));
        await link.waitFor({state:'visible'});
        await link.click({noWaitAfter:true});
        const item=await pending;
        if(item instanceof Error) {
            if(!options.continueOnDownloadFailure) throw item;
            failedDownloads.push(filename+': native download event missing');options.progress?.('FAIL native download event '+filename);return null;
        }
        const failure=await item.failure();
        if(failure!==null && options.continueOnDownloadFailure) {
            failedDownloads.push(filename+': '+failure);options.progress?.('FAIL native download '+filename+': '+failure);return null;
        }
        check(failure===null,'private exact-version attachment succeeds');
        await item.saveAs(options.artifacts+'/'+filename);
        return options.artifacts+'/'+filename;
    };
    try {
        const author=await login('author');
        const reviewer=await login('reviewer');
        let doc=options.existingDocument;
        if(!doc) {
            await go(author,'/institution/mvp-workspace/documents');
            doc=await upload(author,'رفع وثيقة PDF أو DOCX',options.pdf,{title:'دليل إجراءات العرض المحلي',description:'وثيقة PDF اصطناعية فعلية للعرض المحلي، لم يبدأ فحصها أمنيًا.'});
            check(true,'fresh native PDF document created from browser');
        }
        await go(author,'/institution/mvp-workspace/documents/'+doc);
        check(await button(author,'قرار الإتاحة المحلية').count()===0,'uploader has no self-release control');
        check(await author.getByRole('link',{name:'تنزيل خاص — غير مفحوص',exact:true}).count()===0,'quarantined version cannot be downloaded');
        if(options.preservePriorDeniedVersion) {
            await upload(author,'إضافة نسخة من ملف',options.pdf);
            check(true,'prior denied decision retained; separate immutable version submitted');
        }
        await release(reviewer,doc);
        const firstDownload=await download(reviewer,'reviewer-original.pdf',doc,fixture.file_targets.pdf.versions[options.preservePriorDeniedVersion?1:0]);
        await go(author,'/institution/mvp-workspace/documents/'+doc);
        await download(author,'author-original.pdf',doc,fixture.file_targets.pdf.versions[options.preservePriorDeniedVersion?1:0]);
        await button(author,'تعديل البيانات').click();
        let dialog=author.getByRole('dialog').last();
        await dialog.getByRole('textbox',{name:/^عنوان الوثيقة/}).fill('دليل الإجراءات — عنوان محدث');
        await dialog.getByRole('textbox',{name:/^الوصف/}).fill('بيانات وصفية محدثة مع حفظ الأصل وتاريخ جميع النسخ.');
        await button(dialog,'حفظ البيانات').click();
        await dialog.waitFor({state:'hidden'});
        await author.getByText('دليل الإجراءات — عنوان محدث',{exact:true}).waitFor();
        check(await author.getByText('دليل الإجراءات — عنوان محدث',{exact:true}).count()>0,'native metadata edit readback');
        const appended=await upload(author,'إضافة نسخة من ملف',options.docx);
        check(appended===doc,'new immutable version belongs to original document');
        await release(reviewer,doc);
        await download(reviewer,'reviewer-version-2.docx',doc,fixture.file_targets.pdf.versions[options.preservePriorDeniedVersion?2:1]);
        await go(author,'/institution/mvp-workspace/documents');
        const second=await upload(author,'رفع وثيقة PDF أو DOCX',options.docx,{title:'نموذج DOCX اصطناعي',description:'ملف DOCX حقيقي اصطناعي مرفوع من الواجهة الأصلية، دون فحص أمني.'});
        check(second!==doc,'separate DOCX document has its own identity');
        await release(reviewer,second);
        await download(reviewer,'reviewer-second.docx',second,fixture.file_targets.docx.versions[0]);
        for(const [name,page] of [['author',author],['reviewer',reviewer]]) {
            check(await page.locator('html').getAttribute('dir')==='rtl',name+' Arabic RTL');
            for(const width of [1280,768,375]) {
                await page.setViewportSize({width,height:900});
                await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
                check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+' no viewport overflow '+width);
                await page.screenshot({path:options.artifacts+'/'+name+'-'+width+'.png',fullPage:true});
            }
        }
        return {passed:failedDownloads.length===0,checks,failedDownloads,document:doc,secondDocument:second,firstDownload};
    } catch(error) {
        for(let i=0;i<contexts.length;i++) for(const p of contexts[i].pages()) {
            await p.screenshot({path:options.artifacts+'/journey-failure-'+i+'.png',fullPage:true}).catch(()=>{});
        }
        throw error;
    } finally {
        for(const context of contexts) await context.close();
    }
}
