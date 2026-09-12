import test from 'node:test';
import assert from 'node:assert/strict';
import { compressImage, resizedDimensions, installImageCompression } from '../../resources/js/image-compression.js';

test('resize preserves ratio and does not upscale', () => {
    assert.deepEqual(resizedDimensions(4000,3000,2560),[2560,1920]);
    assert.deepEqual(resizedDimensions(400,300,2560),[400,300]);
});

test('compression profiles, orientation, PNG alpha, cache and valid fallback', async () => {
    let options, dimensions, calls = 0, fail = false;
    globalThis.createImageBitmap = async (_, opts) => { options=opts; return {width:4000,height:3000,close(){}}; };
    globalThis.document = {createElement: () => ({width:0,height:0,getContext(){return {drawImage:(_,x,y,w,h)=>{dimensions=[w,h];}};},toBlob(callback,type){calls++;callback(fail?null:new Blob(['compressed'],{type}));}})};
    const original = new File([new Uint8Array(1024*1024)],'photo.jpg',{type:'image/jpeg'});
    const reference = await compressImage(original);
    assert.equal(reference.type,'image/webp'); assert.deepEqual(dimensions,[2560,1920]);
    assert.equal(options.imageOrientation,'from-image');
    assert.equal(await compressImage(reference),reference); assert.equal(calls,1);
    await compressImage(original,'query'); assert.deepEqual(dimensions,[1920,1440]);
    const png = new File([new Uint8Array(1024*1024)],'alpha.png',{type:'image/png'});
    assert.equal((await compressImage(png)).type,'image/png');
    fail=true;
    const small = new File(['valid'],'fallback.jpg',{type:'image/jpeg'});
    assert.equal(await compressImage(small),small);
    const big = new File([new Uint8Array(11*1024*1024)],'big.jpg',{type:'image/jpeg'});
    await assert.rejects(compressImage(big),/gagal diperkecil/);
});

test('invalid and unreadable files are never uploaded as fallback', async () => {
    await assert.rejects(compressImage(new File([],'empty.jpg',{type:'image/jpeg'})));
    globalThis.createImageBitmap = async () => {throw new Error('corrupt');};
    await assert.rejects(compressImage(new File(['bad'],'broken.jpg',{type:'image/jpeg'})),/tidak dapat dibaca/);
});

test('all forms await sequential compression, retain order, preview output, prevent double submit', async () => {
    let active=0, peak=0, submitted=0;
    globalThis.createImageBitmap=async()=>{active++;peak=Math.max(peak,active);await new Promise(resolve=>setTimeout(resolve,5));return {width:4000,height:3000,close(){active--;}};};
    globalThis.DataTransfer=class { constructor(){this.files=[];this.items={add:file=>this.files.push(file)};} };
    globalThis.CustomEvent=class {constructor(type){this.type=type;}};
    globalThis.HTMLFormElement=class {submit(){submitted++;}};
    const form={events:{},append(){},addEventListener(name,fn){this.events[name]=fn;},reportValidity(){return true;}};
    const input={name:'images[]',form,events:{},files:['first','second'].map(name=>new File(['original'],name+'.jpg',{type:'image/jpeg'})),
        addEventListener(name,fn){this.events[name]=fn;},dispatchEvent(event){assert.equal(event.type,'image:prepared'); assert.ok(this.files.every(file=>file.type==='image/webp'));}};
    globalThis.document={querySelectorAll(){return [input];},createElement(tag){return tag==='p'?{setAttribute(){}}:
        {width:0,height:0,getContext(){return {drawImage(){}};},toBlob(cb,type){cb(new Blob(['x'],{type}));}};}};
    installImageCompression(); input.events.change();
    const button={disabled:false};
    const submit=form.events.submit({preventDefault(){},submitter:button});
    assert.equal(submitted,0); assert.equal(button.disabled,true);
    await form.events.submit({preventDefault(){},submitter:button});
    await submit;
    assert.equal(submitted,1);assert.equal(peak,1);
    assert.deepEqual(input.files.map(file=>file.name),['first.webp','second.webp']);
});
