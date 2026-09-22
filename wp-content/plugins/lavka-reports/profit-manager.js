/* Manager workbook layout. Values come from Java; editable Excel tax formulas mirror its document rounding. */
(function (scope) {
    'use strict';
    const number = (value, money = true) => value == null ? '—' : {type:'number', value:String(value), money};
    const formula = (expression, value, money = true) => ({type:'formula', formula:expression, value:String(value), money});
    const taxLabel = (row, t) => /_TAX_MALAFOP$/.test(row.lineId || '') ? t.retailTax : /_TAX_KONDFOP$/.test(row.lineId || '') ? t.wholesaleTax : row.label;
    const purpose = (row, settings) => {
        const retail=/_TAX_MALAFOP$/.test(row.lineId || ''), wholesale=/_TAX_KONDFOP$/.test(row.lineId || '');
        if(settings && (retail || wholesale))return settings[retail?'retailFirmCodes':'wholesaleFirmCodes'];
        if(Array.isArray(row.filters?.purposeCodes) && (row.filters.purposeCodes.length || !(retail || wholesale)))return row.filters.purposeCodes;
        return retail?['МАЛАФОП']:wholesale?['КОНДФОП']:row.filters?.purposeCodes;
    };
    const selection = (f, key, t) => !f ? '—' : f[key+'WarehouseMode']==='ALL' ? t.allWarehouses : f[key+'WarehouseMode']==='NONE' ? '—' : (f[key+'WarehouseMode']==='EXCLUDE' ? t.exceptWarehouses+': ' : '')+(f[key+'WarehouseIds'] || []).join(', ');
    function sheets(data, t) {
        return ['KYIV','ODESA'].map(city => {
            const inputs=data.inputs || {}, k=inputs.kyivEmployeeCount, o=inputs.odesaEmployeeCount;
            const counts=Number.isInteger(k)&&Number.isInteger(o)&&k>=0&&o>=0&&k+o>0;
            const share=inputs.odesaTaxShare == null ? null : Number(inputs.odesaTaxShare);
            const cityShare=share==null?null:city==='ODESA'?share:1-share;
            const dateValue=Number(data.calculatedAt);
            const date=new Date(data.calculatedAt==null?NaN:Number.isFinite(dateValue)?(dateValue<100000000000?dateValue*1000:dateValue):data.calculatedAt);
            const stamp=Number.isNaN(date.getTime())?'—':new Intl.DateTimeFormat('uk-UA',{timeZone:'Europe/Kyiv',dateStyle:'short',timeStyle:'short'}).format(date);
            const rows=[['',city==='KYIV'?t.kyiv:t.odesa,'','','','','',data.month,''], ['',t.snapshot,'',stamp,'','','',data.complete?t.complete:t.incomplete],
                ['',t.employeeTotal,'','','','','',counts?formula('SUM(H4:H5)',k+o,false):'—'],
                ['',t.kyivEmployees,'','','','','',number(k,false)], ['',t.odesaEmployees,'','','','','',number(o,false)],
                ['',t.retailShare,'','','','','',counts?formula(`H${city==='KYIV'?4:5}/H3`,cityShare,false):number(cityShare,false)],
                [t.managerCriteriaHelp],
                ['№',t.fields.label,t.fields.expenseCodes,t.fields.operationTypes,t.fields.purposeCodes,t.fields.cashWarehouses,t.fields.bankWarehouses,t.siteAmount,t.managerCheck]];
            const lines=Array.isArray(data.expenseLines)?data.expenseLines.filter(r=>r.city===city && !(r.lineId==='ODESA_TAX_KONDFOP' && Number(r.amount)===0 && Number(r.profitImpact)===0 && r.documentCount===0)).sort((a,b)=>(a.sortOrder||0)-(b.sortOrder||0)):[];
            const unavailable=data.sections?.EXPENSES?.status==='UNAVAILABLE' || !Array.isArray(data.expenseLines);
            const refs=new Map(), operating=[];
            lines.forEach((line,i)=>{
                const f=line.filters, manual=line.source && line.source!=='FOLIO';
                rows.push([i+1,taxLabel(line,t),(f?.expenseCodes || []).join(' / ') || (manual?t.manualAmount:'—'),manual?'—':(f?.operationTypes || []).join(' / ')||t.anyFilter,manual?'—':(purpose(line,data.taxDetails?.settings)||[]).join(' / ')||(/_TAX_(MALAFOP|KONDFOP)$/.test(line.lineId || '')?t.noTaxFirms:t.anyFilter),manual?'—':selection(f,'cash',t),manual?'—':selection(f,'bank',t),number(line.amount),'']);
                refs.set(line.lineId,rows.length);
                if(line.accountingTreatment==='OPERATING_EXPENSE')operating.push('H'+rows.length);
            });
            if(unavailable)rows.push([t.unavailable]);
            rows.push([]);
            const result=(data.cities||[]).find(c=>c.city===city)||{};
            const amountRow=(label,value,expression)=>{rows.push(['',label,'','','','','',expression && value!=null?formula(expression,value):number(value),'']);return rows.length;};
            const expensesRow=amountRow(t.fields.operatingExpenses,result.operatingExpenses,!unavailable&&operating.length&&lines.filter(l=>l.accountingTreatment==='OPERATING_EXPENSE').every(l=>l.amount!=null&&l.profitImpact!=null&&Number(l.amount)===Number(l.profitImpact))?'SUM('+operating.join(',')+')':null);
            rows.push([]);
            const grossRow=amountRow(t.fields.baseGrossProfit,result.baseGrossProfit);
            const adjustRow=amountRow(t.fields.manualGrossAdjustments,result.manualGrossAdjustments);
            const grossAdjustedRow=amountRow(t.fields.grossProfit,result.grossProfit,result.baseGrossProfit!=null&&result.manualGrossAdjustments!=null?`H${grossRow}+H${adjustRow}`:null);
            amountRow(t.fields.profit,result.profit,result.grossProfit!=null&&result.operatingExpenses!=null?`H${grossAdjustedRow}-H${expensesRow}`:null);
            if(city==='ODESA'){
                rows.push([]);
                ['income','returns','netContribution','grossProfitAlreadyInBase','grossAdjustmentApplied'].forEach(key=>amountRow(t.fields[key]||key,data.masterClass?.[key]));
            }
            rows.push([],[t.taxFormulaHelp]);
            const headingRows=[1,8,expensesRow], mergeRows=[7,rows.length];
            const taxDocs=(data.documents||[]).filter(d=>(d.expenseLineIds||[]).includes(city+'_TAX_MALAFOP') && d.includedInProfit!==false);
            const taxLine=lines.find(l=>l.lineId===city+'_TAX_MALAFOP');
            // Truncated/old audits retain the authoritative amount. Never build a partial SUM.
            const complete=!unavailable&&!data.controls?.auditTruncated&&taxLine&&taxDocs.length===taxLine.documentCount&&new Set(taxDocs.map(d=>String(d.paymentId))).size===taxDocs.length&&share!=null&&taxDocs.every(d=>d.reportAmount!=null&&d[city==='KYIV'?'kyivAllocation':'odesaAllocation']!=null);
            if(complete && taxDocs.length){
                rows.push(['',t.retailTax,t.csvDate,t.csvDocument,'ID','','',t.fields.reportAmount,t.cityTax]);headingRows.push(rows.length);
                const amounts=[];
                taxDocs.forEach(d=>{
                    const n=rows.length+1;
                    const allocation=counts?`H${n}*$H$5/$H$3`:city==='ODESA'?`H${n}*$H$6`:`H${n}*(1-$H$6)`;
                    const expression=city==='ODESA'?`ROUND(${allocation},2)`:`H${n}-ROUND(${allocation},2)`;
                    rows.push(['','',d.documentDate,d.documentNumber,String(d.paymentId),'','',number(d.reportAmount),formula(expression,d[city==='KYIV'?'kyivAllocation':'odesaAllocation'])]);
                    amounts.push('I'+n);
                });
                rows[refs.get(taxLine.lineId)-1][7]=formula('SUM('+amounts.join(',')+')',taxLine.amount);
            }else { rows.push([t.taxFormulaUnavailable]); mergeRows.push(rows.length); }
            rows.push([t.managerEditHelp]); mergeRows.push(rows.length);
            return {name:city==='KYIV'?t.kyiv:t.odesa,rows,widths:[7,40,23,28,22,20,20,21,24],headerRows:headingRows,freezeRows:8,mergeRows,rowHeight:24,landscape:true};
        });
    }
    const api={sheets,taxLabel,purpose};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;else scope.LavkaProfitManager=api;
})(typeof window!=='undefined'?window:globalThis);
