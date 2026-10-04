import { TestBed } from '@angular/core/testing';
import { TextSizeService } from './text-size.service';

describe('TextSizeService', () => {
  const scale = () => document.documentElement.style.getPropertyValue('--text-scale');

  beforeEach(() => {
    localStorage.clear();
    document.documentElement.style.removeProperty('--text-scale');
  });

  function create(): TextSizeService {
    const service = TestBed.inject(TextSizeService);
    TestBed.tick();
    return service;
  }

  it('starts at 100 % and writes a scale of 1 to the root element', () => {
    const service = create();
    expect(service.percent()).toBe(100);
    expect(scale()).toBe('1');
  });

  it('reads the saved step', () => {
    localStorage.setItem('sfr.textSize', '130');
    const service = create();
    expect(service.percent()).toBe(130);
    expect(scale()).toBe('1.3');
  });

  it('steps up and down through the scale and persists each step', () => {
    const service = create();
    service.increase();
    TestBed.tick();
    expect(service.percent()).toBe(110);
    expect(localStorage.getItem('sfr.textSize')).toBe('110');
    expect(scale()).toBe('1.1');
    service.decrease();
    service.decrease();
    TestBed.tick();
    expect(service.percent()).toBe(90);
    expect(scale()).toBe('0.9');
  });

  it('stops at both ends', () => {
    const service = create();
    service.set(90);
    expect(service.canDecrease()).toBe(false);
    service.decrease();
    expect(service.percent()).toBe(90);
    service.set(150);
    expect(service.canIncrease()).toBe(false);
    service.increase();
    expect(service.percent()).toBe(150);
  });

  it('ignores a value that is not a step', () => {
    const service = create();
    service.set(125);
    expect(service.percent()).toBe(100);
  });

  it('resets to 100 %', () => {
    localStorage.setItem('sfr.textSize', '150');
    const service = create();
    service.reset();
    expect(service.percent()).toBe(100);
    expect(localStorage.getItem('sfr.textSize')).toBe('100');
  });

  it('fills the bar from empty at 90 % to full at 150 %', () => {
    const service = create();
    service.set(90);
    expect(service.fillPercent()).toBeCloseTo(0);
    service.set(120);
    expect(service.fillPercent()).toBeCloseTo(50);
    service.set(150);
    expect(service.fillPercent()).toBeCloseTo(100);
  });
});
