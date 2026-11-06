<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'

const routes = {list:'/compras',edit:'/montar',reports:'/relatorios',jobs:'/importacoes'}
const validPage = () => Object.keys(routes).find(k=>routes[k]===location.pathname) || 'list'
const page=ref(validPage()),session=ref(null),ready=ref(false)
const username=ref(''),password=ref(''),authLoading=ref(false),busy=ref(false),error=ref(''),notice=ref('')
const items=ref([]),editItems=ref([]),products=ref([]),searchText=ref(''),searchBusy=ref(false)
const editorSaving=ref(false),editorStatus=ref('saved'),editorError=ref('')
const listFilter=ref(''),statusFilter=ref('pending'),marketFilter=ref('all')
const statusOptions=[{value:'pending',label:'Pendentes'},{value:'bought',label:'Comprados'},{value:'all',label:'Todos'}]
const replacingId=ref(null),searchInput=ref(null),sources=ref([]),jobs=ref([]),attempts=ref([]),selectedMarket=ref('all')
const marketInfo=ref([]),sourceDraft=ref(null),addingSource=ref(false),sourceBusy=ref(false)
const newSource=ref({supermercado:'confianca',categoria:'',url:'',enabled:true})
const queuePaused=ref(false),imagePreview=ref(''),jobView=ref('running'),clock=ref(Date.now()),jobsLoading=ref(false)
const closing=ref(false),receiptTotal=ref(''),purchaseNote=ref(''),receiptInput=ref(null)
const reportMonths=ref('3'),reportLoading=ref(false),report=ref(null),purchaseDetail=ref(null),detailLoading=ref(false)
const periodOptions=[{value:'1',label:'Este mês'},{value:'3',label:'3 meses'},{value:'6',label:'6 meses'},{value:'9',label:'9 meses'},{value:'12',label:'12 meses'}]
const reportSummary=computed(()=>report.value?.summary||{purchase_count:0,estimated_total:0,actual_total:0,difference:0,difference_percent:null})
const monthlyMax=computed(()=>Math.max(1,...(report.value?.monthly||[]).flatMap(m=>[Number(m.estimated_total),Number(m.actual_total)])))
const barHeight=value=>(Math.max(0,Number(value))/monthlyMax.value*100)+'%'
const reportMonthLabel=value=>new Date(value+'-01T12:00:00').toLocaleDateString('pt-BR',{month:'short',year:'2-digit'})
const detailMarkets=computed(()=>{const groups=[];for(const item of purchaseDetail.value?.items||[]){let g=groups.find(x=>x.market===item.supermercado);if(!g){g={market:item.supermercado,items:[]};groups.push(g)}g.items.push(item)}return groups})
const differenceLabel=value=>Number(value)>0?'Economia frente ao estimado':Number(value)<0?'Acima do estimado':'Dentro do estimado'
let tempId=-1,searchTimer,toastTimer,pollTimer,tickTimer,requestNumber=0
let editorSaveTimer=null,editorSavePromise=null,editorBaseline=new Map()
const markets={confianca:'Confiança',tauste:'Tauste'}
// A administração lista todos os raspadores; compras só mostram lojas úteis.
const availableMarkets=computed(()=>Object.entries(markets).filter(([id])=>
  marketInfo.value.some(m=>m.alias===id&&m.visible) || items.value.some(i=>i.supermercado===id)
))
const canShowMarketFilter=computed(()=>availableMarkets.value.length>1)
const money=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v||0))
const number=v=>new Intl.NumberFormat('pt-BR',{maximumFractionDigits:2}).format(Number(v||0))
const norm=v=>String(v||'').toLocaleLowerCase('pt-BR').normalize('NFD').replace(/[\u0300-\u036f]/g,'')
const itemValue=x=>Number(x.qtd||0)*Number(x.preco_atual??x.preco??0)
const total=computed(()=>items.value.reduce((n,x)=>n+itemValue(x),0))
const boughtItems=computed(()=>items.value.filter(x=>Number(x.oculto)).length)
const pending=computed(()=>items.value.length-boughtItems.value)
const multipleMarkets=computed(()=>new Set(items.value.map(x=>x.supermercado)).size>1)
const statusCounts=computed(()=>({pending:pending.value,bought:boughtItems.value,all:items.value.length}))
// O restante considera somente os itens pendentes e seus preços atuais.
const remainingValue=computed(()=>items.value.filter(x=>!Number(x.oculto)).reduce((n,x)=>n+itemValue(x),0))
const progress=computed(()=>items.value.length?Math.round(boughtItems.value/items.value.length*100):0)
const visible=computed(()=>items.value.filter(x=>(statusFilter.value==='all'||(statusFilter.value==='pending'?!Number(x.oculto):Number(x.oculto))) && (marketFilter.value==='all'||marketFilter.value===x.supermercado) && norm([x.titulo,x.categoria,x.supermercado,x.observacao].join(' ')).includes(norm(listFilter.value))))
const grouped=computed(()=>{const out=[];for(const item of visible.value){const key=item.supermercado+'|'+item.categoria;let group=out.find(g=>g.key===key);if(!group){group={key,market:item.supermercado,category:item.categoria,items:[]};out.push(group)}group.items.push(item)}return out})
const editTotal=computed(()=>editItems.value.reduce((n,x)=>n+itemValue(x),0))
const replacementTarget=computed(()=>editItems.value.find(x=>x.id===replacingId.value)||null)
const canChooseProduct=p=>!editItems.value.some(x=>x.id!==replacingId.value&&x.supermercado===p.supermercado&&x.titulo===p.titulo)
// Imagens antigas podem ser absolutas, //cdn..., ou relativas ao mercado.
function directImage(url, market='confianca') {
  const text=String(url||'').trim()
  if (/^https?:\/\//i.test(text)) return text.replace(/^http:\/\//i,'https://')
  if (text.startsWith('//')) return 'https:'+text
  if (text.startsWith('/') && Object.hasOwn(markets,market)) return 'https://www.'+market+'.com.br'+text
  return ''
}
function proxyImage(item) {
  if (!item || !Object.hasOwn(markets,item.supermercado)) return ''
  const id=Number(item.id)
  if (Number.isSafeInteger(id) && id>0) return '/api/images/saved/'+id+(item._imageRevision||item.dataHora?'?v='+encodeURIComponent(item._imageRevision||item.dataHora):'')
  const imageId=Number(item.image_id)
  if (Number.isSafeInteger(imageId) && imageId>0) return '/api/images/catalog/'+item.supermercado+'/'+imageId
  return ''
}
function itemImage(item) {
  if (!item) return ''
  // No rascunho de uma substituição, nunca exibir a foto do ID antigo.
  if (item._replacedLocally) {
    if (Number(item.image_id)>0) return '/api/images/catalog/'+item.supermercado+'/'+item.image_id
    return directImage(item.imgUrl,item.supermercado)
  }
  return proxyImage(item) || directImage(item.imgUrl,item.supermercado)
}
function imageError(event,item) {
  const image=event.target
  const fallback=directImage(item?.imgUrl,item?.supermercado)
  if (!image.dataset.triedOriginal && fallback && image.src!==fallback) {
    image.dataset.triedOriginal='1'
    image.src=fallback
    return
  }
  image.style.display='none'
}
function previewItem(item,event) {
  const img=event?.currentTarget?.querySelector('img')
  if (img && img.complete && img.naturalWidth===0) return
  imagePreview.value=(img?.currentSrc || itemImage(item))
}
function flash(msg,isError=false){notice.value=isError?'':msg;error.value=isError?msg:'';clearTimeout(toastTimer);toastTimer=setTimeout(()=>{notice.value='';error.value=''},4800)}
async function api(path,method='GET',payload=null){const headers={Accept:'application/json'};if(payload!==null)headers['Content-Type']='application/json';if(session.value?.csrf&&method!=='GET')headers['X-CSRF-Token']=session.value.csrf;let response;try{response=await fetch('/api'+path,{method,credentials:'same-origin',headers,body:payload===null?undefined:JSON.stringify(payload)})}catch{throw new Error('Não foi possível conectar ao servidor.')}const data=await response.json().catch(()=>({}));if(!response.ok){if(response.status===401&&path!=='/auth/login')session.value=null;throw new Error(data.error||'Falha ao concluir operação.')}return data}
// A montagem é persistida por diferenças. O PUT legado substituía a lista
// inteira: um autosave baseado nele poderia excluir inclusões de outro familiar.
const editorFields=x=>({
  titulo:x.titulo,categoria:x.categoria,qtd:Number(x.qtd),preco:Number(x.preco),
  supermercado:x.supermercado,imgUrl:directImage(x.imgUrl,x.supermercado),
  observacao:x.observacao||''
})
const editorSignature=x=>JSON.stringify(editorFields(x))
const editorStatusText=computed(()=>editorStatus.value==='saving'?'Salvando alterações...':
  editorStatus.value==='pending'?'Alterações pendentes de salvamento':
  editorStatus.value==='error'?(editorError.value||'Não foi possível salvar. Tente novamente.'):
  '✓ Salvo automaticamente')
function resetEditor(){
  clearTimeout(editorSaveTimer)
  editorBaseline=new Map(items.value.map(x=>[Number(x.id),editorSignature(x)]))
  editItems.value=items.value.map(x=>({...x}))
  replacingId.value=null
  editorError.value=''
  editorStatus.value='saved'
}
function pendingEditorChanges(){
  const currentIds=new Set(editItems.value.map(x=>Number(x.id)).filter(id=>id>0))
  const add=[],update=[],remove=[]
  for(const item of editItems.value){
    const id=Number(item.id),fields=editorFields(item)
    if(id<=0)add.push({clientId:id,...fields})
    else if(editorBaseline.get(id)!==editorSignature(item))update.push({id,...fields,imageReplaced:!!item._replacedLocally})
  }
  for(const id of editorBaseline.keys())if(!currentIds.has(id))remove.push(id)
  return {add,update,remove}
}
const hasEditorChanges=()=>{
  const c=pendingEditorChanges()
  return !!(c.add.length||c.update.length||c.remove.length)
}
function scheduleEditorSave(delay=650){
  clearTimeout(editorSaveTimer)
  if(page.value!=='edit'||!session.value)return
  if(!hasEditorChanges()){
    if(!editorSaving.value){editorStatus.value='saved';editorError.value=''}
    return
  }
  editorStatus.value=editorSaving.value?'saving':'pending'
  editorSaveTimer=setTimeout(()=>{editorSaveTimer=null;void flushEditor()},delay)
}
watch(editItems,()=>{
  if(page.value==='edit'){
    editorError.value=''
    scheduleEditorSave()
  }
},{deep:true})

async function flushEditor(){
  clearTimeout(editorSaveTimer)
  editorSaveTimer=null
  if(editorSavePromise){
    if(!(await editorSavePromise))return false
    return flushEditor()
  }
  if(!session.value)return false
  const changes=pendingEditorChanges()
  if(!changes.add.length&&!changes.update.length&&!changes.remove.length){
    editorStatus.value='saved'
    editorError.value=''
    return true
  }
  if([...changes.add,...changes.update].some(x=>!Number.isFinite(x.qtd)||x.qtd<=0||x.qtd>100000||!Number.isFinite(x.preco))){
    editorError.value='Confira as quantidades: use valores maiores que zero.'
    editorStatus.value='error'
    return false
  }
  // Snapshot das alterações enviadas: o usuário pode continuar digitando
  // durante o fetch sem perder as mudanças mais recentes.
  const submitted=[...changes.add.map(x=>({...x})),...changes.update.map(x=>({...x}))]
  const snapshots=new Map(submitted.map(x=>[x.id??x.clientId,editorSignature(x)]))
  const knownIds=new Set(editorBaseline.keys())
  editorSaving.value=true
  editorStatus.value='saving'
  const request=(async()=>{
    try{
      const result=await api('/shopping','PATCH',changes)
      const created=result.created||{}
      for(const id of changes.remove)editorBaseline.delete(id)
      for(const row of changes.update)editorBaseline.set(row.id,snapshots.get(row.id))
      for(const row of changes.add){
        const id=Number(created[String(row.clientId)])
        if(!Number.isSafeInteger(id)||id<=0)throw new Error('Não foi possível confirmar um produto salvo. Atualize a lista.')
        const local=editItems.value.find(x=>Number(x.id)===row.clientId)
        if(local)local.id=id
        editorBaseline.set(id,snapshots.get(row.clientId))
      }
      items.value=result.items||[]
      const createdIds=new Set(Object.values(created).map(Number))
      for(const serverItem of items.value){
        const id=Number(serverItem.id)
        const local=editItems.value.find(x=>Number(x.id)===id)
        if(local){
          local.oculto=serverItem.oculto
          local.dataHora=serverItem.dataHora
          // Quando a substituição já chegou ao banco, a foto deve usar o ID
          // persistido e o marcador de substituição deixa de ser exibido.
          const submittedSignature=snapshots.get(id)??Object.keys(created).reduce((v,k)=>v??(Number(created[k])===id?snapshots.get(Number(k)):undefined),undefined)
          if(submittedSignature&&editorSignature(local)===submittedSignature){
            if(changes.update.some(x=>x.id===id&&x.imageReplaced))local._imageRevision=Date.now()
            delete local._replacedLocally
            delete local._originalProduct
          }
        }else if(!knownIds.has(id)&&!createdIds.has(id)){
          // Uma inclusão feita por outro membro da família não deve sumir.
          editorBaseline.set(id,editorSignature(serverItem))
          editItems.value.push({...serverItem})
        }
      }
      editorError.value=''
      return true
    }catch(e){
      editorError.value=e.message||'Não foi possível salvar a lista.'
      editorStatus.value='error'
      flash(editorError.value,true)
      return false
    }finally{
      editorSaving.value=false
    }
  })()
  editorSavePromise=request
  const succeeded=await request
  if(editorSavePromise===request)editorSavePromise=null
  if(!succeeded)return false
  // Se a lista mudou enquanto a requisição estava em andamento, envia somente
  // o novo delta, em ordem, sem perder quantidades/observações digitadas.
  return flushEditor()
}
function warnUnsaved(e){
  if(session.value&&(editorSaving.value||hasEditorChanges())){
    e.preventDefault()
    e.returnValue=''
  }
}
function retryWhenOnline(){if(page.value==='edit'&&hasEditorChanges())scheduleEditorSave(0)}

async function loadMarketInfo(){const r=await api('/markets');marketInfo.value=r.markets||[];if(marketFilter.value!=='all'&&!availableMarkets.value.some(([id])=>id===marketFilter.value))marketFilter.value='all'}
async function init(){ready.value=false;try{const r=await api('/auth/session');session.value=r.authenticated?r:null;if(session.value){await loadShopping();await loadMarketInfo();if(page.value==='edit')resetEditor()}}catch(e){flash(e.message,true)}finally{ready.value=true}}
async function login(){authLoading.value=true;try{const s=await api('/auth/login','POST',{username:username.value,password:password.value});session.value={authenticated:true,...s};password.value='';await loadShopping();await loadMarketInfo();if(page.value==='edit')resetEditor();await showPage(validPage(),false)}catch(e){flash(e.message,true)}finally{authLoading.value=false}}
async function logout(){if(page.value==='edit'&&!(await flushEditor()))return;try{await api('/auth/logout','POST',{})}catch{}session.value=null;items.value=[];report.value=null;closing.value=false;page.value='list';history.replaceState({},'','/compras')}
async function loadShopping(){const r=await api('/shopping');items.value=r.items||[]}
async function showPage(id,push=true){
  if(!routes[id])return false
  const previous=page.value
  if(previous==='edit'&&id!=='edit'&&session.value&&!(await flushEditor())){
    flash(editorError.value||'Revise os itens antes de sair.',true)
    if(!push)history.replaceState({},'',routes.edit)
    return false
  }
  page.value=id
  error.value=''
  if(push&&location.pathname!==routes[id])history.pushState({},'',routes[id])
  if(id==='edit'&&previous!=='edit'){
    resetEditor()
    searchText.value=''
    products.value=[]
  }
  if(id==='jobs')await loadJobs()
  if(id==='reports')await loadReports()
  window.scrollTo({top:0,behavior:'instant'})
  return true
}
function popState(){showPage(validPage(),false)}
async function search(){const text=searchText.value.trim();if(text.length<2){requestNumber++;products.value=[];searchBusy.value=false;return}const req=++requestNumber;searchBusy.value=true;try{const r=await api('/search?q='+encodeURIComponent(text));if(req===requestNumber)products.value=r.products||[]}catch(e){if(req===requestNumber)flash(e.message,true)}finally{if(req===requestNumber)searchBusy.value=false}}
watch(searchText,()=>{clearTimeout(searchTimer);searchTimer=setTimeout(search,320)})
function startReplace(item) {
  replacingId.value=item.id
  searchText.value=''
  products.value=[]
  nextTick(()=>{
    searchInput.value?.focus()
    document.getElementById('editor-search')?.scrollIntoView({behavior:'smooth',block:'start'})
  })
}
function undoReplace(item) {
  if (!item._originalProduct) return
  // Se a substituição já foi enviada (ou está em trânsito), desfazê-la também
  // precisa restaurar a foto antiga no banco, não só a descrição na tela.
  const original={...item,...item._originalProduct}
  const restoreImage=editorSaving.value||editorBaseline.get(Number(item.id))!==editorSignature(original)
  Object.assign(item,item._originalProduct)
  if(restoreImage)item._replacedLocally=true
  else delete item._replacedLocally
  delete item._originalProduct
  flash('Substituição desfeita. O produto original foi restaurado.')
}
function cancelReplace() {
  replacingId.value=null
  searchText.value=''
  products.value=[]
}
function chooseProduct(p) {
  if (!canChooseProduct(p)) {flash('Este produto já está na sua lista.');return}
  const selected=replacementTarget.value
  if (selected) {
    // Preserve id, quantidade, observação, data e o status comprado/pendente.
    // A posição original na lista também permanece a mesma.
    if (!selected._originalProduct) selected._originalProduct={
      titulo:selected.titulo,categoria:selected.categoria,preco:selected.preco,
      preco_atual:selected.preco_atual,supermercado:selected.supermercado,
      imgUrl:selected.imgUrl,image_id:selected.image_id
    }
    Object.assign(selected,{
      titulo:p.titulo,categoria:p.categoria||'Sem categoria',
      preco:Number(p.preco),preco_atual:Number(p.preco),
      supermercado:p.supermercado,imgUrl:directImage(p.imgUrl,p.supermercado),
      image_id:p.image_id||null,_replacedLocally:true
    })
    replacingId.value=null
    flash('Produto substituído. Salvamento automático em andamento.')
    searchText.value=''
    products.value=[]
    nextTick(()=>document.getElementById('selected-'+selected.id)?.scrollIntoView({behavior:'smooth',block:'center'}))
    return
  }
  editItems.value.push({id:tempId--,titulo:p.titulo,categoria:p.categoria||'Sem categoria',qtd:1,preco:Number(p.preco),preco_atual:Number(p.preco),imgUrl:directImage(p.imgUrl,p.supermercado),image_id:p.image_id||null,supermercado:p.supermercado,oculto:0,observacao:''})
  searchText.value=''
  products.value=[]
  flash('Produto adicionado. A lista será salva automaticamente.')
  nextTick(()=>{searchInput.value?.focus();scheduleEditorSave(0)})
}
function removeEditItem(item) {
  if (replacingId.value===item.id) cancelReplace()
  editItems.value=editItems.value.filter(x=>x.id!==item.id)
  nextTick(()=>scheduleEditorSave(0))
}
async function save(){if(busy.value)return;if(await flushEditor()){flash('Lista compartilhada atualizada.');await showPage('list')}}
async function toggle(item){if(busy.value)return;busy.value=true;const next=!Number(item.oculto);let promptClose=false;try{await api('/shopping/'+item.id+'/purchase','PATCH',{bought:next});item.oculto=next?1:0;promptClose=next&&items.value.length&&items.value.every(x=>Number(x.oculto))}catch(e){flash(e.message,true)}finally{busy.value=false;if(promptClose)openClosing()}}
function openClosing(){if(busy.value||!items.value.length||pending.value!==0)return;receiptTotal.value='';purchaseNote.value='';closing.value=true;nextTick(()=>receiptInput.value?.focus())}
function closeClosing(){if(!busy.value)closing.value=false}
function normalizeReceipt(value){const text=String(value||'').trim().replace(/\s/g,'');if(!/^\d{1,7}([,.]\d{1,2})?$/.test(text))return null;return text.replace(',','.')}
async function finishPurchase(){
  if(busy.value)return
  const amount=normalizeReceipt(receiptTotal.value)
  if(amount===null){flash('Informe o valor pago em reais, como 123,45. Use até duas casas decimais.',true);receiptInput.value?.focus();return}
  busy.value=true
  let closed
  try{closed=(await api('/purchases','POST',{actual_total:amount,note:purchaseNote.value})).purchase}
  catch(e){flash(e.message,true);await loadShopping().catch(()=>{});busy.value=false;return}
  // A confirmação ocorreu no banco. Mesmo que uma nova consulta falhe, não
  // mostrar erro de "não registrado" nem oferecer um segundo fechamento.
  closing.value=false
  for(const item of items.value)item.oculto=0
  busy.value=false
  await loadShopping().catch(()=>{})
  await showPage('reports')
  if(report.value?.purchases?.some(p=>Number(p.id)===Number(closed.id)))await openPurchase(closed.id)
  flash('Compra #'+closed.id+' registrada. Sua lista está pronta para a próxima compra!')
}
async function loadReports(){
  if(reportLoading.value)return
  reportLoading.value=true
  try{const data=await api('/purchases?months='+reportMonths.value);report.value=data;purchaseDetail.value=null}
  catch(e){flash(e.message,true)}finally{reportLoading.value=false}
}
async function openPurchase(id){
  if(detailLoading.value)return
  if(Number(purchaseDetail.value?.id)===Number(id)){purchaseDetail.value=null;return}
  detailLoading.value=true
  try{const result=await api('/purchases/'+id);purchaseDetail.value=result.purchase}
  catch(e){flash(e.message,true)}finally{detailLoading.value=false}
}
async function restore(){
  if(busy.value||!boughtItems.value)return
  if(!confirm(`Reiniciar ${boughtItems.value} ${boughtItems.value===1?'item comprado':'itens comprados'}? Todos voltarão a pendentes na lista compartilhada. Produtos, quantidades e alterações ainda não salvas serão preservados.`))return
  busy.value=true
  try{
    await api('/shopping/restore-all','POST',{})
    // Atualiza as duas visões sem descartar alterações em andamento na montagem.
    for(const item of items.value)item.oculto=0
    for(const item of editItems.value)item.oculto=0
    flash('Itens comprados voltaram para pendentes.')
  }catch(e){flash(e.message,true)}finally{busy.value=false}
}
async function clear(){if(!confirm('Excluir todos os itens da lista compartilhada? Esta ação não pode ser desfeita.'))return;busy.value=true;try{await api('/shopping','DELETE');items.value=[];flash('Lista esvaziada.')}catch(e){flash(e.message,true)}finally{busy.value=false}}
function onKeydown(e){if(e.key==='Escape'){imagePreview.value='';closeClosing()}}
function scrollTop(){window.scrollTo({top:0,behavior:'smooth'})}

async function loadJobs(){if(jobsLoading.value)return;jobsLoading.value=true;try{const [ss,jj,mm]=await Promise.all([api('/sources'),api('/jobs'),api('/markets')]);sources.value=ss.sources||[];jobs.value=jj.jobs||[];attempts.value=jj.attempts||[];marketInfo.value=mm.markets||[];queuePaused.value=!!jj.paused}catch(e){flash(e.message,true)}finally{jobsLoading.value=false}}
function startSourceEdit(source){
  addingSource.value=false
  sourceDraft.value={uid:source.uid,supermercado:source.supermercado,categoria:source.categoria,url:source.url,enabled:!!Number(source.enabled)}
}
function cancelSourceEdit(){sourceDraft.value=null}
function showSourceAdd(){sourceDraft.value=null;addingSource.value=!addingSource.value;newSource.value={supermercado:'confianca',categoria:'',url:'',enabled:true}}
async function submitSource(source){
  if(sourceBusy.value)return
  sourceBusy.value=true
  try{
    const uid=source.uid
    const payload={supermercado:source.supermercado,categoria:source.categoria.trim(),url:source.url.trim(),enabled:!!source.enabled}
    await api(uid?'/sources/'+encodeURIComponent(uid):'/sources',uid?'PUT':'POST',payload)
    flash(uid?'Fonte atualizada.':'Fonte cadastrada. Ela entrará na próxima importação automática.')
    sourceDraft.value=null;addingSource.value=false
    await loadJobs()
  }catch(e){flash(e.message,true)}finally{sourceBusy.value=false}
}
async function setSourceEnabled(source){
  if(sourceBusy.value)return
  if(Number(source.enabled)&&!confirm('Desativar esta fonte? Importações pendentes serão dispensadas, sem remover produtos já importados nem o histórico.'))return
  await submitSource({...source,enabled:!Number(source.enabled)})
}
async function removeSource(source){
  if(sourceBusy.value||!confirm('Excluir definitivamente a fonte '+source.categoria+'? Fontes com histórico só podem ser desativadas.'))return
  sourceBusy.value=true
  try{await api('/sources/'+encodeURIComponent(source.uid),'DELETE');flash('Fonte excluída.');await loadJobs()}
  catch(e){flash(e.message,true)}finally{sourceBusy.value=false}
}
async function run(source){if(busy.value||queuePaused.value)return;busy.value=true;try{await api('/sources/'+encodeURIComponent(source.uid)+'/run','POST',{});flash('Categoria adicionada à fila de processamento.');await loadJobs()}catch(e){flash(e.message,true)}finally{busy.value=false}}
async function runMarket(market){if(busy.value||queuePaused.value||!confirm('Reprocessar todas as categorias de '+markets[market]+'?'))return;busy.value=true;try{const r=await api('/markets/'+market+'/run','POST',{});flash(r.job_ids.length+' categorias enfileiradas.');await loadJobs()}catch(e){flash(e.message,true)}finally{busy.value=false}}
async function runAll(){
  if(busy.value||queuePaused.value||!activeSourceCount.value)return
  if(!confirm('Iniciar uma nova importação de todas as '+activeSourceCount.value+' fontes ativas? As importações existentes e o agendamento das 9h serão preservados.'))return
  busy.value=true
  try{const r=await api('/jobs/run-all','POST',{});flash(r.job_ids.length+' fontes adicionadas à execução geral.');await loadJobs()}
  catch(e){flash(e.message,true)}finally{busy.value=false}
}
async function pauseQueue(){
  if(busy.value||(!queuePaused.value&&!queueCount.value&&!runningCount.value))return
  if(!queuePaused.value&&!confirm('Pausar o processamento da fila? Os trabalhos já iniciados serão concluídos. A pausa continuará ativa até você clicar em “Retomar fila”, inclusive amanhã às 9h.'))return
  busy.value=true
  try{const r=await api('/jobs/pause','POST',{paused:!queuePaused.value});queuePaused.value=!!r.paused;flash(queuePaused.value?'Fila pausada até ser retomada manualmente, inclusive para as próximas importações diárias.':'Fila retomada.');await loadJobs()}
  catch(e){flash(e.message,true)}finally{busy.value=false}
}
async function retry(job){if(busy.value||queuePaused.value)return;busy.value=true;try{await api('/jobs/'+job.id+'/retry','POST',{});flash('Nova tentativa enfileirada.');await loadJobs()}catch(e){flash(e.message,true)}finally{busy.value=false}}
const jobLabels={pending:'Pendente',queued:'Na fila',running:'Executando',retry:'Nova tentativa',success:'Concluído',failed:'Falhou',superseded:'Substituída'}
const activeStatuses=['pending','queued','running','retry']
const isActive=j=>activeStatuses.includes(j.status)
const isFinished=j=>['success','failed','superseded'].includes(j.status)
const allActive=computed(()=>jobs.value.filter(isActive))
const runningCount=computed(()=>jobs.value.filter(j=>j.status==='running').length)
const queueCount=computed(()=>jobs.value.filter(j=>['pending','queued','retry'].includes(j.status)).length)
const errorCount=computed(()=>jobs.value.filter(j=>j.status==='failed').length)
const succeededCount=computed(()=>jobs.value.filter(j=>j.status==='success').length)
const jobsFiltered=computed(()=>jobs.value.filter(j=>selectedMarket.value==='all'||j.supermercado===selectedMarket.value))
const currentJobs=computed(()=>jobsFiltered.value.filter(isActive).sort((a,b)=>({'running':0,queued:1,pending:2,retry:3}[a.status]??4)-({'running':0,queued:1,pending:2,retry:3}[b.status]??4)))
const historyJobs=computed(()=>jobsFiltered.value.filter(isFinished))
const marketSources=computed(()=>Object.entries(markets).map(([id,name])=>({id,name,entries:sources.value.filter(s=>s.supermercado===id)})).filter(m=>m.entries.length>0))
const executableMarkets=computed(()=>marketSources.value.filter(m=>m.entries.some(s=>Number(s.enabled))))
const historyMarkets=computed(()=>Object.entries(markets).filter(([id])=>sources.value.some(s=>s.supermercado===id)||jobs.value.some(j=>j.supermercado===id)))
const activeSourceCount=computed(()=>sources.value.filter(s=>Number(s.enabled)===1).length)
const canPauseQueue=computed(()=>queuePaused.value||queueCount.value>0||runningCount.value>0)
const latestFor=uid=>jobs.value.find(j=>j.source_uid===uid&&j.status!=='superseded')||jobs.value.find(j=>j.source_uid===uid)
function parsedDate(v){if(!v)return null;const d=new Date(String(v).replace(' ','T'));return Number.isFinite(d.getTime())?d:null}
const elapsed=(from,to)=>{const a=parsedDate(from);if(!a)return null;const b=to?parsedDate(to):new Date(clock.value);return b?Math.max(0,Math.floor((b.getTime()-a.getTime())/1000)):null}
function duration(seconds){if(seconds==null)return '—';const h=Math.floor(seconds/3600),m=Math.floor((seconds%3600)/60),s=seconds%60;return h?`${h}h ${String(m).padStart(2,'0')}min`:(m?`${m}min ${String(s).padStart(2,'0')}s`:`${s}s`)}
const jobTime=j=>duration(elapsed(j.started_at,j.finished_at))
const dateTime=v=>{const d=parsedDate(v);return d?d.toLocaleString('pt-BR',{dateStyle:'short',timeStyle:'short'}):'—'}
// Data amigável apenas nas importações; em dias anteriores, sempre DD/MM/AAAA.
function importDate(v,withTime=true){
  const d=parsedDate(v)
  if(!d)return '—'
  const today=new Date(clock.value)
  const dayNumber=x=>Date.UTC(x.getFullYear(),x.getMonth(),x.getDate())/86400000
  const difference=dayNumber(today)-dayNumber(d)
  const label=difference===0?'Hoje':difference===1?'Ontem':difference===2?'Anteontem':d.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'})
  return withTime?label+' às '+d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}):label
}
const absoluteImportDate=v=>{const d=parsedDate(v);return d?d.toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}):'—'}
const batches=computed(()=>{const groups=new Map();for(const j of jobs.value){const key=j.batch_key||'legacy:'+j.id;if(!groups.has(key))groups.set(key,{key,jobs:[],maxId:0});const g=groups.get(key);g.jobs.push(j);g.maxId=Math.max(g.maxId,Number(j.id))}return [...groups.values()].map(g=>{const jj=g.jobs,success=jj.filter(j=>j.status==='success').length,failed=jj.filter(j=>j.status==='failed').length,running=jj.filter(j=>j.status==='running').length,skipped=jj.filter(j=>j.status==='superseded').length;const starts=jj.map(j=>parsedDate(j.started_at)?.getTime()).filter(Boolean),ends=jj.map(j=>parsedDate(j.finished_at)?.getTime()).filter(Boolean);const start=starts.length?new Date(Math.min(...starts)).toISOString():null;const finished=jj.every(isFinished)&&ends.length?new Date(Math.max(...ends)).toISOString():null;return {...g,total:jj.length,success,failed,running,skipped,completed:success+failed+skipped,started:start,finished,active:jj.some(isActive),products:jj.reduce((n,j)=>n+Number(j.products_count||0),0)}}).sort((a,b)=>b.maxId-a.maxId)})
const currentBatch=computed(()=>batches.value.find(b=>b.active)||batches.value[0]||null)
const batchPercent=b=>b?.total?Math.round(b.completed/b.total*100):0
function batchName(b){
  if(!b)return 'Nenhuma execução registrada'
  if(b.key.startsWith('daily:'))return 'Importação automática · '+importDate(b.key.substring(6)+'T12:00:00',false)
  if(b.key.startsWith('manual-all:'))return 'Importação geral'
  if(b.key.startsWith('manual:'))return b.jobs.length>1?'Importação de supermercado':'Reprocessamento manual'
  return 'Execução anterior #'+b.maxId
}
const batchTime=b=>b?duration(elapsed(b.started,b.finished)): '—'
const batchEta=b=>{if(!b||!b.active||!b.completed||!b.started)return '—';const seconds=elapsed(b.started,null);return seconds==null?'—':duration(Math.ceil(seconds/b.completed*(b.total-b.completed)))}
const attemptsFor=id=>attempts.value.filter(a=>Number(a.job_id)===Number(id))
const completedNote=a=>a.status==='success'?'Concluída':'Falha'
onMounted(async()=>{window.addEventListener('popstate',popState);window.addEventListener('keydown',onKeydown);window.addEventListener('beforeunload',warnUnsaved);window.addEventListener('online',retryWhenOnline);if(location.pathname==='/'||!Object.values(routes).includes(location.pathname))history.replaceState({},'',routes[page.value]);tickTimer=setInterval(()=>clock.value=Date.now(),1000);pollTimer=setInterval(()=>{if(session.value&&page.value==='jobs')loadJobs()},10000);await init();if(session.value&&page.value==='jobs')loadJobs();if(session.value&&page.value==='reports')loadReports()})
onUnmounted(()=>{window.removeEventListener('popstate',popState);window.removeEventListener('keydown',onKeydown);window.removeEventListener('beforeunload',warnUnsaved);window.removeEventListener('online',retryWhenOnline);clearTimeout(editorSaveTimer);clearInterval(pollTimer);clearInterval(tickTimer);clearTimeout(searchTimer);clearTimeout(toastTimer)})
</script>

<template>
  <div v-if="!ready" class="loading-screen"><div class="spinner"></div><span>Carregando a lista da família...</span></div>
  <div v-else-if="!session" class="login-page">
    <div class="login-decor"><img class="login-decor-logo" src="/branding/ChupaCabra-vertical-fundo-escuro.svg" alt="ChupaCabra — Lista de Compras" width="265" height="194"/><p class="eyebrow">LISTA COMPARTILHADA DA FAMÍLIA</p><h1>Sua compra. Seu ritmo.</h1><p>Lista compartilhada, preços atualizados e praticidade do planejamento até o caixa.</p><div class="decor-pills"><span>Lista compartilhada</span><span>Preços dos mercados</span><span>Compra acompanhada</span></div></div>
    <form class="login-card" @submit.prevent="login"><div class="login-mini"><img class="login-card-logo" src="/branding/ChupaCabra-horizontal-fundo-claro.svg" alt="ChupaCabra — Lista de Compras" width="248" height="75"/></div><p class="eyebrow">ÁREA DA FAMÍLIA</p><h2>Bom ter você aqui.</h2><p class="muted">Acesse a lista de compras da família.</p><label>Usuário<input v-model.trim="username" autocomplete="username" required maxlength="80" autofocus placeholder="Seu usuário"/></label><label>Senha<input v-model="password" autocomplete="current-password" type="password" required placeholder="Sua senha"/></label><button class="button primary full" :disabled="authLoading">{{authLoading?'Entrando...':'Entrar no ChupaCabra →'}}</button><small class="muted">Acesso privado · conexão protegida</small></form>
  </div>
  <div v-else class="shell" :class="'page-'+page">
    <aside class="sidebar"><button class="brand" type="button" @click="showPage('list')" aria-label="ChupaCabra — Ir à lista de compras"><img class="sidebar-logo" src="/branding/ChupaCabra-horizontal-fundo-escuro.svg" alt="ChupaCabra — Lista de Compras" width="206" height="62"/></button><p class="nav-caption">MENU PRINCIPAL</p><nav class="nav"><button :class="{active:page==='list'}" @click="showPage('list')"><span>▤</span>Lista de compras</button><button :class="{active:page==='edit'}" @click="showPage('edit')"><span>＋</span>Montar lista</button><button :class="{active:page==='reports'}" @click="showPage('reports')"><span>▥</span>Relatórios</button><button :class="{active:page==='jobs'}" @click="showPage('jobs')"><span>↻</span>Importações</button></nav><div class="sidebar-bottom"><div class="side-family"><span class="online-dot"></span><div><strong>Espaço compartilhado</strong><small>Da família, sempre atualizado</small></div></div><button class="logout" @click="logout">Sair da conta ↗</button></div></aside>
    <main class="main"><div class="top"><span class="crumb"><img class="header-logo" src="/branding/ChupaCabra-horizontal-fundo-claro.svg" alt="ChupaCabra — Lista de Compras" width="116" height="35"/><span class="crumb-text">ChupaCabra</span><span class="crumb-separator">/</span> {{page==='list'?'Lista de compras':page==='edit'?'Montar lista':page==='reports'?'Relatórios':'Importações'}}</span><div class="top-right"><span class="pill sync"><span class="online-dot"></span>Lista compartilhada</span><span class="avatar">{{session.username?.substring(0,1).toUpperCase()}}</span></div></div>
      <div class="content">
        <template v-if="page==='list'">
          <header class="page-heading shopping-page-heading">
            <div>
              <h1>Lista de compras</h1>
              <p class="shopping-quick-meta">
                <span>{{items.length}} {{items.length===1?'produto':'produtos'}}</span>
                <span class="shopping-meta-dot" aria-hidden="true">·</span>
                <span>Total estimado: <strong>{{money(total)}}</strong></span>
              </p>
            </div>
            <div class="heading-actions"><button v-if="items.length&&pending===0" class="button purchase-finish" :disabled="busy" @click="openClosing">✓ Encerrar compra</button><button class="button primary shopping-edit-button" @click="showPage('edit')">＋ Editar lista</button></div>
          </header>
          <div v-if="items.length&&pending===0" class="closing-banner" role="status"><span class="closing-banner-symbol">✓</span><div><strong>Todos os produtos foram marcados!</strong><p>Registre o total do cupom fiscal para comparar o gasto real com o valor estimado. Os preços serão guardados no histórico.</p></div><button class="button primary" :disabled="busy" @click="openClosing">Encerrar compra →</button></div>
          <section class="panel shopping-panel"><div class="panel-heading shopping-panel-heading"><div><h2>O que levar?</h2><p class="muted">Marque como comprado ou amplie a foto do produto.</p></div><button class="button outline small" @click="loadShopping">↻ Atualizar</button></div><div class="filters"><input v-model="listFilter" type="search" placeholder="⌕ Buscar produto ou observação" aria-label="Buscar na lista"/><div class="status-toggle" role="group" aria-label="Filtrar por situação de compra"><button v-for="option in statusOptions" :key="option.value" type="button" :class="{active:statusFilter===option.value}" :aria-pressed="statusFilter===option.value" @click="statusFilter=option.value">{{option.label}} <span class="status-count">{{statusCounts[option.value]}}</span></button></div><select v-if="canShowMarketFilter" v-model="marketFilter" aria-label="Filtrar supermercado"><option value="all">Todos os mercados</option><option v-for="entry in availableMarkets" :key="entry[0]" :value="entry[0]">{{entry[1]}}</option></select></div>
            <div v-if="!visible.length" class="empty"><div class="empty-icon">🛒</div><h3>{{items.length&&pending===0&&statusFilter==='pending'?'Compra concluída!':items.length?'Nada neste filtro':'A lista ainda está vazia'}}</h3><p>{{items.length&&pending===0&&statusFilter==='pending'?'Todos os itens já foram marcados. Parabéns!':items.length?'Experimente outro filtro ou confira os comprados.':'Pesquise produtos e prepare a próxima compra.'}}</p><button class="button primary" @click="showPage('edit')">Montar lista</button></div>
            <div v-for="group in grouped" :key="group.key" class="group"><div class="group-heading"><span class="market-dot" :class="group.market"></span><strong>{{markets[group.market]||group.market}} · {{group.category}}</strong><small>{{group.items.length}} {{group.items.length===1?'item':'itens'}}</small></div><div v-for="item in group.items" :key="item.id" class="shopping-item" :class="{bought:Number(item.oculto)}"><button class="check" :class="{checked:Number(item.oculto)}" :aria-label="Number(item.oculto)?'Voltar para pendentes':'Marcar como comprado'" @click="toggle(item)" :disabled="busy">{{Number(item.oculto)?'✓':''}}</button><button type="button" class="thumb" :disabled="!itemImage(item)" @click="previewItem(item,$event)" :aria-label="'Ampliar foto de '+item.titulo"><span class="thumb-placeholder" aria-hidden="true">◈</span><img v-if="itemImage(item)" :src="itemImage(item)" loading="lazy" :alt="'Foto de '+item.titulo" @error="imageError($event,item)"/></button><div class="product-main"><strong>{{item.titulo}}</strong><small v-if="item.observacao" class="note">✎ {{item.observacao}}</small><small v-else class="muted">{{item.categoria}}</small></div><div class="product-meta"><strong>{{number(item.qtd)}} × {{money(item.preco_atual??item.preco)}}</strong><span>{{money(itemValue(item))}}</span></div></div></div>
            <div v-if="items.length" class="list-footer"><button class="link-btn" :disabled="busy||!boughtItems" @click="restore">↶ Reiniciar comprados <span v-if="boughtItems">({{boughtItems}})</span></button><button class="link-btn danger" :disabled="busy" @click="clear">Esvaziar lista</button></div>
          </section>
        </template>
        <template v-else-if="page==='edit'">
          <header class="page-heading editor-page-heading">
            <div><p class="eyebrow">PLANEJE SUA COMPRA</p><h1>Monte sua lista</h1><p class="muted">Pesquise, adicione e ajuste seus produtos em uma única tela. A lista permanece organizada pela ordem de inclusão.</p></div>
            <button class="button outline" @click="showPage('list')">← Voltar à compra</button>
          </header>
          <section class="editor-shell" id="editor-main">
            <div class="panel editor-search-panel" id="editor-search">
              <div class="editor-search">
                <label class="search-field" for="catalog-search"><span class="search-glyph" aria-hidden="true">⌕</span>
                  <input id="catalog-search" ref="searchInput" v-model="searchText" type="search" placeholder="Buscar produto, marca ou categoria" aria-label="Buscar produtos no catálogo" autocomplete="off" />
                  <span v-if="searchBusy" class="tiny-spinner" aria-label="Pesquisando"></span>
                </label>
                <button class="button primary" type="button" @click="search">Buscar</button>
              </div>
              <div v-if="replacementTarget" class="replacement-banner" role="status">
                <span class="replacement-symbol" aria-hidden="true">↔</span>
                <div><strong>Substituindo: {{replacementTarget.titulo}}</strong><small>Escolha um produto abaixo. Quantidade, observação, situação e posição na lista serão preservadas.</small></div>
                <button type="button" class="button outline small" @click="cancelReplace">Cancelar</button>
              </div>
              <div v-else class="editor-context"><span>{{searchText.trim().length<2?'Busque no catálogo para adicionar à lista':searchBusy?'Buscando produtos...':products.length+' resultados encontrados'}}</span><span class="editor-search-count">{{editItems.length}} selecionados · {{money(editTotal)}}</span></div>
            </div>

            <section v-if="searchText.trim().length>=2" class="search-results" aria-label="Resultados da pesquisa">
              <div class="search-results-heading"><h2>{{replacementTarget?'Escolha o substituto':'Resultados da pesquisa'}}</h2><button type="button" class="link-btn" @click="searchText='';products=[]">Limpar pesquisa ×</button></div>
              <div v-if="searchBusy&&!products.length" class="search-empty"><p>Pesquisando produtos...</p></div>
              <div v-else-if="!products.length" class="search-empty"><h2>Nenhum resultado</h2><p>Tente uma palavra mais curta ou outra marca.</p></div>
              <div v-else class="results-grid">
                <article v-for="(p,i) in products" :key="p.supermercado+p.titulo+i" class="product-card">
                  <button type="button" class="product-card-image" :disabled="!itemImage(p)" @click="previewItem(p,$event)" :aria-label="'Ampliar foto de '+p.titulo"><span class="thumb-placeholder" aria-hidden="true">◈</span><img v-if="itemImage(p)" :src="itemImage(p)" loading="lazy" :alt="'Foto de '+p.titulo" @error="imageError($event,p)"/></button>
                  <div class="product-card-info"><span class="market-label" :class="p.supermercado">{{markets[p.supermercado]}}</span><strong>{{p.titulo}}</strong><small>{{p.categoria}}</small>
                    <div class="product-card-bottom"><b>{{money(p.preco)}}</b><button type="button" class="button small" :class="canChooseProduct(p)?'primary':'outline'" :disabled="!canChooseProduct(p)" @click="chooseProduct(p)">{{!canChooseProduct(p)?'Na lista':replacementTarget?'Substituir':'＋ Adicionar'}}</button></div>
                  </div>
                </article>
              </div>
            </section>

            <section class="panel selection-full" id="editor-selection" aria-label="Produtos da sua lista">
              <div class="panel-heading selection-heading">
                <div><p class="eyebrow">SUA LISTA</p><h2>Produtos selecionados <span class="count">{{editItems.length}}</span></h2><p class="muted">Em ordem de inclusão · Toque em substituir quando o mercado mudar a descrição do produto.</p></div>
                <div class="selection-actions"><strong class="selection-total">{{money(editTotal)}}</strong><button type="button" class="button outline small reset-bought" :disabled="busy||!boughtItems" @click="restore">↶ Reiniciar comprados <span v-if="boughtItems">({{boughtItems}})</span></button></div>
              </div>
              <div v-if="!editItems.length" class="empty"><div class="empty-icon">📝</div><h3>Sua lista está vazia</h3><p>Use a pesquisa acima para adicionar o primeiro produto.</p></div>
              <div class="selected-list">
                <div v-for="item in editItems" :key="item.id" :id="'selected-'+item.id" class="edit-item" :class="{recentlyReplaced:item._replacedLocally}">
                  <button type="button" class="thumb" :disabled="!itemImage(item)" @click="previewItem(item,$event)" :aria-label="'Ampliar foto de '+item.titulo"><span class="thumb-placeholder" aria-hidden="true">◈</span><img v-if="itemImage(item)" :src="itemImage(item)" loading="lazy" :alt="'Foto de '+item.titulo" @error="imageError($event,item)"/></button>
                  <div class="edit-item-content">
                    <div class="edit-item-head">
                      <div><strong>{{item.titulo}}</strong><span class="selected-category">{{item.categoria||'Sem categoria'}}</span><small>{{markets[item.supermercado]||item.supermercado}} · {{money(item.preco_atual??item.preco)}} <span v-if="Number(item.oculto)" class="selected-bought">· Comprado</span> <span v-if="item._replacedLocally" class="replacement-pending">· Substituição sendo salva</span></small></div>
                      <div class="edit-item-actions"><button v-if="item._replacedLocally" type="button" class="button outline small undo-replace" :disabled="busy" @click="undoReplace(item)">↶ Desfazer</button><button type="button" class="button outline small replace-item" :disabled="busy" @click="startReplace(item)" :aria-label="'Substituir '+item.titulo">↔ Substituir</button><button type="button" class="remove-item" :disabled="busy" @click="removeEditItem(item)" :aria-label="'Remover '+item.titulo">×</button></div>
                    </div>
                    <div class="item-fields"><label>Quantidade<input v-model.number="item.qtd" type="number" min="0.001" max="100000" step="0.001" inputmode="decimal"/></label><label class="note-input">Observação<input v-model="item.observacao" type="text" maxlength="500" placeholder="Ex.: 10 uva, 10 laranja e 10 limão"/></label><strong class="edit-line-total">{{money(itemValue(item))}}</strong></div>
                  </div>
                </div>
              </div>
            </section>
            <div class="editor-save-dock"><div class="dock-selection"><small>{{editItems.length}} {{editItems.length===1?'produto':'produtos'}} · Total estimado</small><strong>{{money(editTotal)}}</strong><small class="editor-save-status" :class="editorStatus" role="status">{{editorStatusText}}</small></div><button class="button primary" :disabled="busy" @click="save">{{editorSaving?'Salvando...':'✓ Salvar lista'}}</button></div>
          </section>
        </template>
        <template v-else-if="page==='reports'">
          <header class="page-heading reports-heading"><div><p class="eyebrow">HISTÓRICO DE COMPRAS</p><h1>Quanto valeu a pena?</h1><p class="muted">Compare o total calculado pelos preços do catálogo no fechamento com o valor pago no caixa.</p></div><button class="button outline" :disabled="reportLoading" @click="loadReports">↻ Atualizar</button></header>
          <div class="period-picker" role="group" aria-label="Período do relatório"><button v-for="option in periodOptions" :key="option.value" :class="{active:reportMonths===option.value}" :aria-pressed="reportMonths===option.value" :disabled="reportLoading" @click="reportMonths=option.value;loadReports()">{{option.label}}</button></div>
          <div v-if="reportLoading&&!report" class="empty">Carregando os relatórios...</div>
          <template v-if="report">
            <div class="report-kpis">
              <div class="report-kpi main-kpi"><small>{{Number(reportSummary.difference)>=0?'SALDO EM RELAÇÃO À ESTIMATIVA':'GASTO ACIMA DA ESTIMATIVA'}}</small><strong :class="{negative:Number(reportSummary.difference)<0}">{{money(Math.abs(Number(reportSummary.difference)))}}</strong><span>{{Number(reportSummary.difference)===0?'Estimativa e gasto ficaram iguais':Number(reportSummary.difference)>0?'Você gastou menos do que a estimativa':'Você gastou mais do que a estimativa'}}<template v-if="reportSummary.difference_percent!==null"> · {{Math.abs(Number(reportSummary.difference_percent)).toLocaleString('pt-BR')}}%</template></span></div>
              <div class="report-kpi"><small>TOTAL ESTIMADO</small><strong>{{money(reportSummary.estimated_total)}}</strong><span>Preços congelados no fechamento</span></div>
              <div class="report-kpi"><small>TOTAL PAGO</small><strong>{{money(reportSummary.actual_total)}}</strong><span>Conforme os valores informados</span></div>
              <div class="report-kpi"><small>COMPRAS REGISTRADAS</small><strong>{{reportSummary.purchase_count}}</strong><span>No período selecionado</span></div>
            </div>
            <section class="panel report-chart-panel"><div class="panel-heading"><div><h2>Estimado x pago por mês</h2><p class="muted">A comparação considera todas as compras encerradas em cada mês.</p></div><div class="chart-legend"><span><i class="estimate-swatch"></i> Estimado</span><span><i class="actual-swatch"></i> Pago</span></div></div>
              <div class="monthly-chart" role="img" aria-label="Gráfico mensal de valores estimados e pagos"><div v-for="m in report.monthly" :key="m.month" class="month-column"><div class="month-bars"><div class="month-bar estimated-bar" :style="{height:barHeight(m.estimated_total)}" :title="'Estimado: '+money(m.estimated_total)"></div><div class="month-bar actual-bar" :style="{height:barHeight(m.actual_total)}" :title="'Pago: '+money(m.actual_total)"></div></div><strong>{{reportMonthLabel(m.month)}}</strong><small>{{m.count}} {{m.count===1?'compra':'compras'}}</small></div></div>
              <details v-if="reportSummary.purchase_count" class="monthly-ledger"><summary>Ver valores por mês</summary><div class="monthly-ledger-scroll"><table><thead><tr><th>Mês</th><th>Compras</th><th>Estimado</th><th>Pago</th><th>Diferença</th></tr></thead><tbody><tr v-for="m in report.monthly" :key="m.month"><td>{{reportMonthLabel(m.month)}}</td><td>{{m.count}}</td><td>{{money(m.estimated_total)}}</td><td>{{money(m.actual_total)}}</td><td :class="{negative:Number(m.difference)<0}">{{money(m.difference)}}</td></tr></tbody></table></div></details>
              <p v-if="!reportSummary.purchase_count" class="report-empty-note">Ainda não há compras fechadas neste período. Marque os itens da lista e informe o total pago para começar seu histórico.</p>
            </section>
            <section class="panel report-history"><div class="panel-heading"><div><h2>Compras realizadas</h2><p class="muted">Selecione uma compra para consultar os produtos e seus preços registrados naquele dia.</p></div><span class="count">{{report.purchases.length}}</span></div>
              <div v-if="!report.purchases.length" class="empty slim-empty">Nenhuma compra registrada neste período.</div>
              <div v-for="purchase in report.purchases" :key="purchase.id" class="report-purchase-wrap">
                <button type="button" class="report-purchase" :aria-expanded="Number(purchaseDetail?.id)===Number(purchase.id)" @click="openPurchase(purchase.id)"><div class="report-purchase-identity"><strong>Compra #{{purchase.id}}</strong><small>{{dateTime(purchase.completed_at)}} · {{purchase.item_count}} {{Number(purchase.item_count)===1?'item':'itens'}}</small></div><div class="report-purchase-values"><span>Estimado <b>{{money(purchase.estimated_total)}}</b></span><span>Pago <b>{{money(purchase.actual_total)}}</b></span></div><span class="delta-tag" :class="{negative:Number(purchase.difference)<0,neutral:Number(purchase.difference)===0}">{{Number(purchase.difference)>0?'-':Number(purchase.difference)<0?'+':''}}{{money(Math.abs(Number(purchase.difference)))}}</span><span class="report-expand">{{Number(purchaseDetail?.id)===Number(purchase.id)?'⌃':'⌄'}}</span></button>
                <div v-if="Number(purchaseDetail?.id)===Number(purchase.id)" class="purchase-details"><div class="detail-heading"><div><strong>{{differenceLabel(purchaseDetail.difference)}}</strong><small>Fechada por {{purchaseDetail.recorded_by}} · Valores por produto são estimados, não preços reais do cupom.</small></div><strong class="detail-savings" :class="{negative:Number(purchaseDetail.difference)<0}">{{money(Math.abs(Number(purchaseDetail.difference)))}}</strong></div><p v-if="purchaseDetail.note" class="purchase-note">✎ {{purchaseDetail.note}}</p><div v-for="group in detailMarkets" :key="group.market" class="snapshot-group"><h3>{{markets[group.market]||group.market}}</h3><div v-for="item in group.items" :key="item.saved_item_id+'-'+item.titulo" class="snapshot-line"><div><strong>{{item.titulo}}</strong><small v-if="item.observacao">✎ {{item.observacao}}</small><small v-else>{{item.categoria}}</small></div><div class="snapshot-amount"><small>{{number(item.quantidade)}} × {{money(item.estimated_unit_price)}}</small><strong>{{money(item.estimated_subtotal)}}</strong></div></div></div></div>
                <div v-if="detailLoading&&!purchaseDetail" class="detail-loading">Carregando produtos…</div>
              </div>
            </section>
            <p class="report-disclaimer">* Economia significa pagar menos que a estimativa, não necessariamente obter desconto: substituições, pesagem e diferenças no cupom podem afetar o resultado. O valor real é registrado por compra, não por produto ou supermercado.</p>
          </template>
        </template>
        <template v-else-if="page==='jobs'">
          <header class="page-heading import-heading">
            <div>
              <p class="eyebrow">ATUALIZAÇÃO DO CATÁLOGO</p>
              <h1>Central de importações</h1>
              <p class="muted">Atualização automática às 9h · 5 workers · novas tentativas em caso de falha.</p>
            </div>
            <div class="jobs-actions">
              <button type="button" class="button primary" :disabled="busy||queuePaused||!activeSourceCount" @click="runAll">↻ Iniciar importação geral</button>
              <button v-if="canPauseQueue" type="button" class="button outline" :disabled="busy" @click="pauseQueue">{{queuePaused?'▶ Retomar fila':'⏸ Pausar fila'}}</button>
              <button type="button" class="button outline import-refresh" :disabled="jobsLoading" @click="loadJobs" :aria-label="jobsLoading?'Atualizando importações':'Atualizar importações'" title="Atualizar importações">↻ <span>Atualizar</span></button>
            </div>
          </header>
          <p v-if="queuePaused" class="import-pause-warning" role="status">⏸ Fila pausada até você retomá-la. Nenhuma nova categoria será iniciada, inclusive na atualização automática de amanhã às 9h.</p>

          <div class="stats import-stats">
            <div class="stat hero-stat"><small>EXECUTANDO AGORA</small><strong>{{runningCount}} <span class="slash-stat">/ 5</span></strong><span>{{queuePaused?'Fila pausada':'Workers simultâneos'}}</span></div>
            <div class="stat"><small>AGUARDANDO</small><strong>{{queueCount}}</strong><span>Pendentes e novas tentativas</span></div>
            <div class="stat"><small>CONCLUÍDOS</small><strong>{{succeededCount}}</strong><span>Execuções recentes</span></div>
            <div class="stat"><small>FALHAS</small><strong>{{errorCount}}</strong><span>Exigem atenção</span></div>
          </div>

          <section class="panel import-live" aria-label="Progresso da importação">
            <div class="panel-heading">
              <div><p class="eyebrow">ACOMPANHAMENTO DA FILA</p><h2>{{batchName(currentBatch)}}</h2><p class="muted">{{currentBatch?.completed||0}} de {{currentBatch?.total||0}} fontes finalizadas<span v-if="currentBatch?.started"> · Início: {{importDate(currentBatch.started)}}</span></p></div>
              <span class="live-status" :class="{isLive:currentBatch?.active&&!queuePaused,isPaused:queuePaused}"><span class="live-dot"></span>{{queuePaused?'Pausada':currentBatch?.active?'Em andamento':currentBatch?(currentBatch.failed?'Finalizada com falhas':currentBatch.skipped===currentBatch.total?'Substituída':'Concluída'):'Sem execução'}}</span>
            </div>
            <div v-if="currentBatch" class="live-content">
              <div class="live-progress-line"><strong>{{batchPercent(currentBatch)}}% concluído</strong><span>{{number(currentBatch.products)}} produtos · {{batchTime(currentBatch)}} decorridos<span v-if="currentBatch.active&&!queuePaused"> · Previsão: {{batchEta(currentBatch)}}</span></span></div>
              <div class="progress-track"><div class="progress-fill" :style="{width:batchPercent(currentBatch)+'%'}"></div></div>
              <div class="live-counters"><span><b>{{currentBatch.total}}</b> fontes</span><span><b>{{currentBatch.success}}</b> sucessos</span><span><b>{{currentBatch.failed}}</b> falhas</span><span v-if="currentBatch.skipped"><b>{{currentBatch.skipped}}</b> substituídas</span><span><b>{{currentBatch.running}}</b> executando</span></div>
            </div>
            <div v-else class="empty slim-empty">Nenhuma importação registrada. Inicie todas as fontes ou escolha um supermercado abaixo.</div>
          </section>

          <div class="import-dashboard-grid">
            <section class="panel sources-accordion">
              <div class="panel-heading source-heading"><div><h2>Fontes e desempenho <span class="count">{{activeSourceCount}}</span></h2><p class="muted">Configure categorias e URLs. Novos mercados exigem desenvolvimento do raspador.</p></div><button type="button" class="button outline small" :disabled="sourceBusy" @click="showSourceAdd">{{addingSource?'Cancelar':'＋ Nova fonte'}}</button></div>
              <form v-if="addingSource" class="source-form add-source-form" @submit.prevent="submitSource(newSource)">
                <h3>Cadastrar categoria</h3>
                <div class="source-fields"><label>Supermercado<select v-model="newSource.supermercado"><option v-for="(name,id) in markets" :key="id" :value="id">{{name}}</option></select></label><label>Categoria<input v-model="newSource.categoria" maxlength="100" required placeholder="Ex.: Hortifruti"/></label></div>
                <label>Endereço da categoria (HTTPS)<input v-model="newSource.url" type="url" maxlength="2048" required placeholder="https://www.exemplo.com.br/categoria"/></label>
                <label class="source-check"><input v-model="newSource.enabled" type="checkbox"/> Ativar importação automática</label>
                <div class="source-form-actions"><button type="button" class="button outline small" @click="addingSource=false">Cancelar</button><button type="submit" class="button primary small" :disabled="sourceBusy">Salvar fonte</button></div>
              </form>
              <div class="source-market-list">
                <p v-if="!marketSources.length" class="empty slim-empty">Nenhuma fonte cadastrada. Utilize “Nova fonte” para começar.</p>
                <details v-for="(m,i) in marketSources" :key="m.id" class="source-market" :open="i===0">
                  <summary><span class="market-icon" :class="m.id">{{m.id==='confianca'?'C':'T'}}</span><strong>{{m.name}}</strong><small>{{m.entries.length}} categorias</small><span class="chevron">⌄</span></summary>
                  <div class="source-row" v-for="s in m.entries" :key="s.uid" :class="{'source-off':!Number(s.enabled)}">
                    <template v-if="sourceDraft?.uid===s.uid">
                      <form class="source-form source-inline-form" @submit.prevent="submitSource(sourceDraft)">
                        <h3>Editar · {{m.name}}</h3>
                        <label>Categoria<input v-model="sourceDraft.categoria" maxlength="100" required/></label>
                        <label>URL da categoria<input v-model="sourceDraft.url" type="url" maxlength="2048" required/></label>
                        <label class="source-check"><input v-model="sourceDraft.enabled" type="checkbox"/> Fonte ativa</label>
                        <div class="source-form-actions"><button type="button" class="button outline small" @click="cancelSourceEdit">Cancelar</button><button type="submit" class="button primary small" :disabled="sourceBusy">Salvar alterações</button></div>
                      </form>
                    </template>
                    <template v-else>
                      <div class="source-name"><strong>{{s.categoria}} <span v-if="!Number(s.enabled)" class="source-inactive">Inativa</span></strong><small :title="s.url">{{s.url}}</small></div>
                      <div class="source-last" v-if="latestFor(s.uid)"><span class="state" :class="latestFor(s.uid).status">{{jobLabels[latestFor(s.uid).status]}}</span><small>{{number(latestFor(s.uid).products_count)}} produtos · {{jobTime(latestFor(s.uid))}}</small><small :title="absoluteImportDate(latestFor(s.uid).finished_at||latestFor(s.uid).created_at)">{{importDate(latestFor(s.uid).finished_at||latestFor(s.uid).created_at)}}</small></div>
                      <div class="source-last" v-else><small>Ainda não importada</small></div>
                      <div class="source-actions"><button type="button" class="button outline small" :disabled="busy||queuePaused||!Number(s.enabled)" :aria-label="'Reprocessar '+s.categoria+' do '+m.name" title="Reprocessar agora" @click="run(s)">↻</button><button type="button" class="button outline small" :disabled="sourceBusy" title="Editar categoria e endereço" :aria-label="'Editar '+s.categoria" @click="startSourceEdit(s)">✎</button><button type="button" class="button outline small" :disabled="sourceBusy" :title="Number(s.enabled)?'Desativar fonte':'Ativar fonte'" @click="setSourceEnabled(s)">{{Number(s.enabled)?'⏸':'▶'}}</button><button v-if="!latestFor(s.uid)" type="button" class="button outline small" :disabled="sourceBusy" title="Excluir fonte sem histórico" @click="removeSource(s)">×</button></div>
                    </template>
                  </div>
                </details>
              </div>
            </section>

            <div class="import-side-stack">
              <section class="panel import-controls">
                <div class="panel-heading"><div><h2>Executar por supermercado</h2><p class="muted">Inicie um mercado sem interromper a lista de compras.</p></div></div>
                <div v-if="!executableMarkets.length" class="empty slim-empty">Nenhum supermercado possui fontes ativas.</div><div class="control-market" v-for="m in executableMarkets" :key="m.id"><span class="market-icon" :class="m.id">{{m.id==='confianca'?'C':'T'}}</span><div><strong>{{m.name}}</strong><small>{{m.entries.filter(s=>Number(s.enabled)).length}} fontes ativas</small></div><button type="button" class="button outline small" :disabled="busy||queuePaused||!m.entries.some(s=>Number(s.enabled))" @click="runMarket(m.id)">↻ Iniciar</button></div>
              </section>
              <section class="panel running-panel">
                <div class="panel-heading"><div><h2>Atividade atual <span v-if="currentJobs.length" class="count">{{currentJobs.length}}</span></h2><p class="muted">Categorias em execução ou aguardando processamento.</p></div></div>
                <div v-if="!currentJobs.length" class="empty slim-empty"><span class="quiet-symbol">◷</span><p>Nenhuma categoria aguardando ou em processamento.</p></div>
                <div class="current-job-list" v-else>
                  <div class="job-compact" v-for="j in currentJobs.slice(0,20)" :key="j.id"><div><strong>{{j.categoria}}</strong><small>{{markets[j.supermercado]}} · #{{j.id}}</small></div><div class="job-side"><span class="state" :class="j.status">{{jobLabels[j.status]}}</span><small>{{j.status==='running'?jobTime(j):j.status==='retry'?'Próxima: '+importDate(j.next_attempt_at):'Aguardando worker'}}</small></div></div>
                </div>
                <p v-if="currentJobs.length>20" class="more-jobs">Mais {{currentJobs.length-20}} categorias aguardando</p>
              </section>
            </div>
          </div>

          <section class="panel history-panel">
            <div class="panel-heading"><div><h2>Histórico de importações</h2><p class="muted">Lotes recentes e tentativas individuais, com datas e duração.</p></div><select v-model="selectedMarket" aria-label="Filtrar supermercado"><option value="all">Todos os mercados</option><option v-for="entry in historyMarkets" :key="entry[0]" :value="entry[0]">{{entry[1]}}</option></select></div>
            <div class="batch-history">
              <div class="batch-chip" v-for="b in batches.slice(0,8)" :key="b.key"><div><strong>{{batchName(b)}}</strong><small :title="absoluteImportDate(b.started||b.jobs[0]?.created_at)">{{importDate(b.started||b.jobs[0]?.created_at)}} · {{b.completed}}/{{b.total}} fontes</small><small>{{number(b.products)}} produtos<span v-if="b.skipped"> · {{b.skipped}} substituídas</span></small></div><span>{{batchTime(b)}}</span></div>
            </div>
            <details class="history-details"><summary>Ver histórico por categoria ({{historyJobs.length}})</summary><div class="history-scroll">
              <div v-for="j in historyJobs.slice(0,100)" :key="j.id" class="history-job"><div class="history-job-line"><div><strong>{{markets[j.supermercado]}} · {{j.categoria}}</strong><small :title="absoluteImportDate(j.created_at)">#{{j.id}} · {{importDate(j.created_at)}}</small></div><span class="state" :class="j.status">{{jobLabels[j.status]}}</span></div><div class="history-job-metrics"><span v-if="j.status==='superseded'">Sem nova raspagem</span><span v-else>{{number(j.products_count)}} produtos</span><span v-if="j.started_at">Duração: <b>{{jobTime(j)}}</b></span><span>Tentativas: {{j.attempts}}/{{j.max_attempts}}</span></div><div v-if="j.error_message" class="job-error" :class="{'job-info':j.status==='superseded'}">{{j.error_message}}</div><div class="job-footer"><button type="button" class="link-btn" :disabled="busy||queuePaused" @click="retry(j)">↻ Reprocessar</button><details v-if="attemptsFor(j.id).length"><summary>Tentativas ({{attemptsFor(j.id).length}})</summary><div v-for="a in attemptsFor(j.id)" :key="a.attempt_number" class="attempt"><span>Tentativa {{a.attempt_number}} · {{completedNote(a)}}</span><span :title="absoluteImportDate(a.started_at)">{{importDate(a.started_at)}} · {{duration(elapsed(a.started_at,a.finished_at))}}</span><p v-if="a.error_message">{{a.error_message}}</p></div></details></div></div>
              <div v-if="!historyJobs.length" class="empty slim-empty">Ainda não há execuções concluídas.</div>
            </div></details>
          </section>
        </template>
      </div>
    </main>
    <div v-if="page==='list'&&items.length" class="shopping-bottom" aria-label="Andamento da compra">
      <button v-if="pending===0" class="bottom-edit bottom-complete" :disabled="busy" @click="openClosing">✓ <span>Encerrar</span></button>
      <button v-else class="bottom-edit" @click="showPage('edit')">✎ <span>Editar</span></button>
      <div class="bottom-summary">
        <div class="bottom-main" aria-label="Quantidade e valor ainda não comprados">
          <span class="bottom-needed" :class="{finished:pending===0}">
            Faltam <strong>{{pending}}</strong><span class="bottom-unit">{{pending===1?'item':'itens'}}</span>
          </span>
          <span class="bottom-spend" :class="{finished:pending===0}" :title="'Valor estimado restante: '+money(remainingValue)">
            <span class="bottom-spend-label-full">Restante</span><span class="bottom-spend-label-short">Rest.</span>
            <strong>{{money(remainingValue)}}</strong>
          </span>
        </div>
        <div class="bottom-meta">
          <span>{{boughtItems}}/{{items.length}} <span class="bottom-bought-label">comprados</span> · {{progress}}%</span>
          <span :title="'Total estimado: '+money(total)">Total {{money(total)}}</span>
        </div>
        <div class="progress-track" role="progressbar" :aria-label="boughtItems+' de '+items.length+' produtos comprados'" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
          <div class="progress-fill" :style="{width:progress+'%'}"></div>
        </div>
      </div>
      <button class="bottom-top" aria-label="Voltar ao topo" @click="scrollTop">↑</button>
    </div>
    <div class="mobile-nav"><button :class="{active:page==='list'}" @click="showPage('list')"><span>▤</span>Lista</button><button :class="{active:page==='edit'}" @click="showPage('edit')"><span>＋</span>Montar</button><button :class="{active:page==='reports'}" @click="showPage('reports')"><span>▥</span>Relatórios</button><button :class="{active:page==='jobs'}" @click="showPage('jobs')"><span>↻</span>Importações</button><button @click="logout"><span>↗</span>Sair</button></div>
  </div>
  <div v-if="closing" class="purchase-overlay" role="presentation" @click.self="closeClosing"><div class="purchase-dialog" role="dialog" aria-modal="true" aria-labelledby="close-title"><div class="purchase-dialog-head"><span class="purchase-check">✓</span><div><p class="eyebrow">FECHAMENTO DA COMPRA</p><h2 id="close-title">Tudo no carrinho!</h2></div><button type="button" class="purchase-dismiss" :disabled="busy" aria-label="Fechar" @click="closeClosing">×</button></div><p class="purchase-intro">Informe o total efetivamente pago no caixa. Os preços estimados de todos os itens serão salvos junto com o resultado.</p><div class="purchase-compare"><div><small>Total estimado pela lista</small><strong>{{money(total)}}</strong></div><span>↔</span><div><small>Produtos comprados</small><strong>{{items.length}}</strong></div></div><form @submit.prevent="finishPurchase"><label class="receipt-label" for="receipt-total">Valor total pago (R$)<input id="receipt-total" ref="receiptInput" v-model="receiptTotal" type="text" inputmode="decimal" autocomplete="off" required maxlength="12" placeholder="Ex.: 278,49" aria-describedby="receipt-help"/></label><small id="receipt-help" class="muted">{{multipleMarkets?'Sua lista inclui mais de um supermercado: informe a soma dos cupons fiscais.':'Use o total do cupom fiscal, com até 2 casas decimais.'}}</small><label class="receipt-label purchase-note-label" for="receipt-note">Observação (opcional)<input id="receipt-note" v-model="purchaseNote" type="text" maxlength="250" placeholder="Ex.: Compra do fim de semana"/></label><p class="receipt-reminder">Ao encerrar, a compra entra no histórico e os produtos voltam a ficar pendentes para reutilizar esta mesma lista.</p><div class="purchase-dialog-actions"><button type="button" class="button outline" :disabled="busy" @click="closeClosing">Agora não</button><button type="submit" class="button primary" :disabled="busy">{{busy?'Registrando...':'✓ Salvar e ver relatório'}}</button></div></form></div></div>
  <div v-if="imagePreview" class="image-overlay" role="dialog" aria-modal="true" aria-label="Imagem ampliada do produto" @click.self="imagePreview=''" @keydown.esc="imagePreview=''"><button class="image-close" @click="imagePreview=''" aria-label="Fechar ampliação">×</button><img :src="imagePreview" alt="Foto ampliada do produto" referrerpolicy="no-referrer"/></div>
  <div v-if="notice||error" class="toast" :class="{isError:error}" role="status">{{error||notice}}</div>
</template>
