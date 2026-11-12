export async function reassessmentJourney(browser, f, options) {
 const checks=[],contexts=[];const check=(value,name)=>{if(!value)throw new Error(name);checks.push(name);options.progress?.(name);};
 const button=(p,name)=>p.getByRole('button',{name,exact:true});const go=(p,path)=>p.goto(options.base+path,{waitUntil:'domcontentloaded',timeout:120000});
 const submit=async(p,control)=>{const pending=p.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname===f.livewire_update&&r.request().postDataJSON()?.components?.some(c=>c.calls?.some(call=>call.method==='callMountedAction')));await control.click();const response=await pending;check(response.status()===200,'exact native reassessment Action response acknowledged');await response.finished();};
 const path=i=>'/institution/mvp-workspace/workflows/'+f.approach+'/maturity/'+f.stages[i];
 const login=async name=>{const ctx=await browser.newContext({viewport:{width:1280,height:900},reducedMotion:'reduce'});contexts.push(ctx);const p=await ctx.newPage();p.setDefaultTimeout(60000);
  await go(p,'/institution/login');const g=p.getByRole('group',{name:'تسجيل الدخول',exact:true});for(const [label,k] of [['معرّف المؤسسة','organization'],['البريد المسجل','email'],['كلمة المرور','password']])await g.getByRole('textbox',{name:label,exact:true}).fill(f.accounts[name][k]);
  await button(g,'تسجيل الدخول').click();await p.getByRole('heading',{name:'الحساب المؤسسي',exact:true}).waitFor();check(true,name+' actual independent login for reassessment');return p;};
 const open=async(p,i)=>{await go(p,path(i));await button(p,'طلب إعادة تقييم').click();const confirm=button(p,'بدء المحاولة');await submit(p,confirm);await confirm.waitFor({state:'hidden'});await button(p,'تحرير إعادة التقييم').waitFor();check(true,'persistent independent attempt '+(i+1));};
 const save=async(p,i,text)=>{await button(p,'تحرير إعادة التقييم').click();const d=p.getByRole('dialog').last();const answer=d.getByRole('textbox',{name:/^سؤال المرحلة/});await answer.fill(text);
  for(const label of ['شاهد أول للمرحلة ','شاهد ثان للمرحلة ']){const select=d.getByRole('combobox',{name:label+(i+1),exact:true});const values=await select.locator('option').evaluateAll(nodes=>nodes.map(n=>n.value).filter(Boolean));const match=values.find(v=>v.toLowerCase()===options.evidence.toLowerCase());check(Boolean(match),'explicit exact-version evidence remains currently authorized '+(i+1));await select.selectOption(match);}
  await submit(p,button(d,'حفظ مسودة إعادة التقييم'));await d.waitFor({state:'hidden'});await go(p,path(i));await p.getByText(text,{exact:true}).first().waitFor();check(await p.getByText(text,{exact:true}).count()===2,'new immutable answer readback in current and reassessment sections '+(i+1));};
 const decide=async(p,i,decision,band,reason)=>{await go(p,path(i));await button(p,'مراجعة إعادة التقييم').click();const d=p.getByRole('dialog').last();await d.getByRole('combobox',{name:/^قرار إعادة التقييم/}).selectOption(decision);if(band!==null)await d.getByRole('combobox',{name:/^التصنيف اليدوي في السلم الأصلي/}).selectOption(String(band));await d.getByRole('textbox',{name:/^تعليل القرار وكفاية الشواهد/}).fill(reason);await submit(p,button(d,'حفظ قرار إعادة التقييم'));await d.waitFor({state:'hidden'});await go(p,path(i));check(true,'independent '+decision+' decision read back '+(i+1));};
 try {
  const author=await login('author'),reviewer=await login('reviewer');
  await go(author,path(0));check(await author.getByText('تقدم تجريبي',{exact:true}).count()>0,'initial approved higher category visible');
  if(options.resumeSubmitted){await go(author,path(0));check(await author.getByText('إجابة إعادة التقييم الأولى من المتصفح تحتاج تفصيلًا.',{exact:true}).count()===2,'retained submitted attempt verified before resuming');}
  else{await open(author,0);await save(author,0,'إجابة إعادة التقييم الأولى من المتصفح تحتاج تفصيلًا.');}
  check(await button(author,'مراجعة إعادة التقييم').count()===0,'author has no self-approval control');
  await decide(reviewer,0,'changes_requested',null,'أضف تفصيل التطبيق؛ التصنيف السابق يبقى نافذًا.');
  await go(author,path(0));check(await author.getByText('تقدم تجريبي',{exact:true}).count()>0,'correction preserves effective prior category');
  await go(author,path(2));check(await author.getByText('تلزم إعادة التحقق',{exact:true}).count()===0,'correction does not invalidate downstream stages');
  await go(author,path(0));await save(author,0,'إجابة إعادة التقييم المصححة مع وصف التطبيق والشاهد المحدد.');
  await decide(reviewer,0,'approved',0,'اعتماد مستقل لتراجع التصنيف بعد مراجعة الإجابة المصححة وكفاية الشاهد المحلي.');
  await go(author,path(0));check(await author.getByText('بداية تجريبية',{exact:true}).count()>0,'lower effective category survives reload');
  await go(author,path(2));await author.getByText('تلزم إعادة التحقق',{exact:true}).waitFor();check(await button(author,'طلب إعادة تقييم').count()===0,'stage three blocked until stage two independent revalidation');
  for(let i=1;i<5;i++){await open(author,i);await save(author,i,'إعادة تحقق متسلسلة للمرحلة '+(i+1)+' مع شاهد مختار صراحة.');await decide(reviewer,i,'approved',1,'أعدت التحقق مستقلًا من اعتماد المرحلة السابقة وكفاية الشاهد؛ أبقي التصنيف السابق.');await go(author,path(i));check(await author.getByText('تلزم إعادة التحقق',{exact:true}).count()===0,'sequential dependency restored '+(i+1));}
  await open(author,0);await save(author,0,'محاولة مساوية للتصنيف الحالي دون تغيير المراحل اللاحقة.');await decide(reviewer,0,'approved',0,'اعتماد مستقل بتصنيف مساوٍ مع بقاء صلاحية المراحل اللاحقة.');
  await go(author,path(4));check(await author.getByText('تلزم إعادة التحقق',{exact:true}).count()===0,'equal category preserves downstream validity');
  await open(author,0);await save(author,0,'محاولة تحسن لاحقة محفوظة مع إبقاء جميع القرارات السابقة.');await decide(reviewer,0,'approved',1,'اعتماد مستقل بتصنيف أعلى دون أي تعديل تلقائي لتصنيفات المراحل الأخرى.');
  await go(author,path(4));check(await author.getByText('تلزم إعادة التحقق',{exact:true}).count()===0,'higher category preserves downstream validity');
  await go(reviewer,path(0));await button(reviewer,'سجل إعادة التقييم والمقارنة').click();const history=reviewer.getByRole('dialog').last();
  await history.getByText('إجابة المرحلة 1 محفوظة من المتصفح مع الشواهد.',{exact:true}).waitFor();
  check(await history.getByText('إجابة إعادة التقييم المصححة مع وصف التطبيق والشاهد المحدد.',{exact:true}).count()>0,'original and corrected historical answers remain visible');
  check(await history.getByText('محاولة تحسن لاحقة محفوظة مع إبقاء جميع القرارات السابقة.',{exact:true}).count()>0,'latest approved attempt shown alongside original history');
  await button(reviewer,'إغلاق').last().click();await history.waitFor({state:'hidden'});
  for(const width of [1280,768,375]){await reviewer.setViewportSize({width,height:900});await reviewer.evaluate(()=>document.fonts.ready);check(await reviewer.locator('html').getAttribute('dir')==='rtl','reassessment RTL '+width);check(await reviewer.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'reassessment no overflow '+width);await reviewer.screenshot({path:options.artifacts+'/reassessment-'+width+'.png',fullPage:true});}
  await go(reviewer,'/institution/mvp-workspace/notifications');await button(reviewer,'تعليم كمقروء').first().click();await reviewer.getByText('مقروء',{exact:true}).first().waitFor();check(true,'atomic reassessment notification is readable and marks read');
  for(const [p,name] of [[author,'author'],[reviewer,'reviewer']]){await go(p,'/institution/'+f.accounts[name].organization+'/account');await button(p,'تسجيل الخروج').click();await p.getByRole('group',{name:'تسجيل الدخول',exact:true}).waitFor();check(true,name+' actual logout');}
  return {passed:true,checks};
 }catch(error){for(let i=0;i<contexts.length;i++)for(const p of contexts[i].pages())await p.screenshot({path:options.artifacts+'/reassessment-failure-'+i+'.png',fullPage:true}).catch(()=>{});throw error;}
 finally{for(const ctx of contexts)await ctx.close();}
}
