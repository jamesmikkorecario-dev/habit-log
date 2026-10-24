import {test,expect} from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {readFileSync,writeFileSync} from 'node:fs';
import {action} from './support.js';
const root='storage/app/entry-history-refinements';
const fixture=JSON.parse(readFileSync(`${root}/fixtures.json`));
const {ids,today,future}=fixture;
const observed={pages:[],layouts:[],axe:[],errors:[]};
test.afterEach(()=>writeFileSync(`${root}/observations.json`,JSON.stringify(observed,null,2)));
async function login(page,email='history-review@example.test'){
 await page.goto('/login');await page.getByLabel('Email address').fill(email);await page.getByLabel('Password',{exact:true}).fill('Browser-test-123!');await page.getByRole('button',{name:'Log in',exact:true}).click();await page.waitForURL(/\/dashboard$/);
}
async function capture(page,name){
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:960});await page.evaluate(async()=>{await document.fonts.ready;scrollTo(0,0);});
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  const heights=await page.locator('[data-entry-history]').evaluateAll(regions=>regions.flatMap(el=>[...el.querySelectorAll('a,button')].map(control=>control.getBoundingClientRect().height).filter(height=>height>0)));for(const height of heights)expect(height).toBeGreaterThanOrEqual(44);
  const region=await page.locator('main').count()?page.locator('main'):page.locator('body');
  observed.layouts.push({name,...await region.evaluate(el=>({width:innerWidth,pageWidth:document.documentElement.scrollWidth,heading:el.querySelector('h1')?.innerText,links:[...el.querySelectorAll('a,button')].filter(e=>e.getBoundingClientRect().height).map(e=>({name:e.textContent.trim(),height:e.getBoundingClientRect().height,width:e.getBoundingClientRect().width,x:e.getBoundingClientRect().x}))}))});
  await page.screenshot({path:`${root}/${name}-${width}.png`,fullPage:true});
 }
}
test('entry history preserves scores, snapshots, paging, privacy and accessible mobile layouts',async({page,browser})=>{
 test.setTimeout(180000);
 page.on('pageerror',e=>observed.errors.push(e.message));await login(page);
 for(const type of ['boolean','count','duration','checkpoint','avoidance']){
  const response=await page.goto(`/habits/${ids[type]}/entries`);expect(response.status()).toBe(200);
  await expect(page.locator('main article')).toHaveCount(4);
  const dates=await page.locator('main article h2').allTextContents();expect(dates).toEqual([...dates].sort().reverse());
  expect(dates[0]).toBe(today);
  const markup=await page.content();expect(markup).not.toContain('PRIVATE-HISTORY-NOTE');expect(markup).not.toContain('PRIVATE-HISTORY-REASON');
  await expect(page.locator('[data-history-performance]').first()).toHaveText({boolean:'100%',count:'50%',duration:'50%',checkpoint:'100%',avoidance:'0%'}[type]);
  await expect(page.locator('[data-history-performance]').last()).toHaveText('N/A · Neutral');
  if(type==='checkpoint')await expect(page.locator('[data-history-checkpoints]').nth(1)).toHaveText('1 of 3 completed');
  else if(type!=='boolean')await expect(page.locator('main article').first()).toContainText('Personal assessment');
  observed.pages.push({name:type,text:await page.locator('main').innerText(),dates});await capture(page,type);
  await page.locator('main article').first().getByRole('link',{name:/^View entry for /}).focus();await page.keyboard.press('Enter');await expect(page).toHaveURL(new RegExp(`/entries/${today}$`));
  await expect(page.locator('#entry-note')).toHaveValue('PRIVATE-HISTORY-NOTE');
  if(type==='checkpoint')await expect(page.locator('[data-checkpoint-progress]')).toHaveText('3 of 3 completed · 100% · Completed');
  await page.goto(`/habits/${ids[type]}`);
  const cell=page.locator(`[data-date="${today}"]`);const label=await cell.getAttribute('aria-label');
  const score={boolean:'100.0',count:'50.0',duration:'50.0',checkpoint:'100.0',avoidance:'0.0'}[type];
  expect(label).toContain(`${score}% performance`);observed.pages.at(-1).actualScore=label;
  const naDate=dates[3];await page.goto(`/habits/${ids[type]}/entries/${naDate}`);await expect(page.locator('#entry-reason')).toHaveValue('PRIVATE-HISTORY-REASON');
 }
 for(const name of ['empty','archived','restored']){
  await page.goto(`/habits/${ids[name]}/entries`);observed.pages.push({name,text:await page.locator('main').innerText()});await capture(page,name);
  if(name==='empty')await expect(page.getByText('No recorded activity yet.',{exact:true})).toBeVisible();
  else await expect(page.locator('main article')).toHaveCount(1);
 }
 await page.goto(`/habits/${ids.archived}/entries`);await page.getByRole('link',{name:/^View entry for /}).click();await expect(page.getByRole('button',{name:'Save activity',exact:true})).toBeVisible();
 observed.archivedHistoricalEditable=true;
 await page.goto(`/habits/${ids.archived}/entries/${today}`);await expect(page.getByText(/This date falls in an archive or trash period/)).toBeVisible();await expect(page.getByRole('button',{name:'Save activity',exact:true})).toHaveCount(0);await capture(page,'archived-gap');
 const deleted=await page.goto(`/habits/${ids.trashed}/entries`);expect(deleted.status()).toBe(404);await capture(page,'trashed-denied');
 await page.goto(`/habits/${ids.long}/entries`);await expect(page.locator('main article')).toHaveCount(20);await capture(page,'long-page-1');
 const first=await page.locator('main article h2').allTextContents();
 // Use the real pagination control, then verify a direct URL restores that page.
 const next=page.getByRole('button',{name:/Next/}).last();await expect(next).toBeVisible();await next.focus();await page.keyboard.press('Enter');
 await expect(page).toHaveURL(/page=2/);await expect(page.locator('main article')).toHaveCount(20);
 const second=await page.locator('main article h2').allTextContents();expect(second[0]<first.at(-1)).toBe(true);
 await capture(page,'long-page-2');observed.pagination={first,second,text:await page.locator('main').innerText()};
 await page.goto(`/habits/${ids.long}/entries?page=3`);await expect(page.locator('main article')).toHaveCount(5);await capture(page,'long-page-3');await expect(page.locator('main article').first()).toContainText('Historical goal');await expect(page.locator('main article').first()).toContainText('10 '+ 'W'.repeat(30));await expect(page.locator('[data-history-performance]').first()).toHaveText('50%');
 observed.pagination.third=await page.locator('main article h2').allTextContents();
 const oldDate=observed.pagination.third[0];await page.locator('main article').first().getByRole('link',{name:/^View entry for /}).click();await expect(page).toHaveURL(new RegExp(`/entries/${oldDate}$`));
 await expect(page.locator('#entry-value')).toHaveValue('5');await expect(page.getByText('Goal: 10 '+ 'W'.repeat(30),{exact:true})).toBeVisible();await capture(page,'historical-editor');
 await page.goto(`/habits/${ids.long}/entries?page=3`);await page.setViewportSize({width:320,height:960});expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
 await page.goto(`/habits/${ids.long}/entries?page=99`);await expect(page.getByText('No entries on this page.',{exact:true})).toBeVisible();await expect(page.getByText('No recorded activity yet.',{exact:true})).toHaveCount(0);await capture(page,'out-of-range');await action(page,()=>page.getByRole('button',{name:'Back to first page',exact:true}).click());await expect(page.locator('main article')).toHaveCount(20);
 await page.goto(`/habits/${ids.travel}/entries`);await expect(page.locator('main article h2')).toHaveText(future);await capture(page,'timezone-history');await expect(page.locator('main article')).toContainText('Read-only');await expect(page.locator('[data-history-performance]')).toHaveText('Neutral · Inactive date');
 await page.getByRole('link',{name:/^View entry for /}).click();await expect(page.getByText(/Future dates cannot be recorded/)).toBeVisible();await expect(page.getByRole('button',{name:'Save activity',exact:true})).toHaveCount(0);await expect(page.locator('[data-entry-history]')).toHaveCount(0);await capture(page,'timezone-read-only');
 await page.goto('/settings/profile');await page.getByLabel('Timezone',{exact:true}).selectOption('America/Los_Angeles');await action(page,()=>page.getByRole('button',{name:'Save changes',exact:true}).click());
 await page.goto(`/habits/${ids.long}/entries`);expect(await page.locator('main article h2').allTextContents()).toEqual(first);observed.timezoneDatesUnchanged=true;
 await page.setViewportSize({width:390,height:960});
 for(const name of ['boolean','long','empty']){
  await page.goto(`/habits/${ids[name]}/entries${name==='long'?'?page=3':''}`);const scan=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa']).analyze();expect(scan.violations).toEqual([]);observed.axe.push({name,violations:scan.violations.map(v=>({id:v.id,impact:v.impact,targets:v.nodes.map(n=>n.target)}))});
 }
 const foreignContext=await browser.newContext({baseURL:'http://127.0.0.1:8017'});const foreign=await foreignContext.newPage();await login(foreign,'history-other@example.test');
 const forbidden=await foreign.goto(`/habits/${ids.count}/entries`);expect(forbidden.status()).toBe(404);expect(await foreign.content()).not.toContain('PRIVATE-HISTORY-NOTE');await foreignContext.close();
 const guestContext=await browser.newContext({baseURL:'http://127.0.0.1:8017'});const guest=await guestContext.newPage();await guest.goto(`/habits/${ids.count}/entries`);await expect(guest).toHaveURL(/\/login$/);await guestContext.close();
 observed.authorization={foreign404:true,guestLogin:true};expect(observed.errors).toEqual([]);
});
