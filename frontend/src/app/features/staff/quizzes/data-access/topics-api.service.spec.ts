import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { TopicsApiService } from './topics-api.service';

describe('TopicsApiService', () => {
  let api: TopicsApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(TopicsApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the folder catalog in name order and pages of twenty', () => {
    api.list(1, 20, 'nameAsc').subscribe();
    const request = http.expectOne('/api/topics?pageIndex=1&pageSize=20&sort=nameAsc');
    expect(request.request.method).toBe('GET');
    request.flush({
      topics: [],
      pagination: { pageIndex: 1, pageSize: 20, totalItems: 0, totalPages: 0 },
    });
  });

  it('uses exact topic detail and CRUD contracts', () => {
    api.get(7).subscribe();
    http.expectOne('/api/topics/7').flush({ topic: {} });

    api.create({ name: 'Scratch', description: null }).subscribe();
    const create = http.expectOne('/api/topics');
    expect(create.request.method).toBe('POST');
    expect(create.request.body).toEqual({ name: 'Scratch', description: null });
    create.flush({ topic: {} }, { status: 201, statusText: 'Created' });

    api.update(7, { name: 'Scratch 2' }).subscribe();
    const update = http.expectOne('/api/topics/7');
    expect(update.request.method).toBe('PATCH');
    expect(update.request.body).toEqual({ name: 'Scratch 2' });
    update.flush({ topic: {} });

    api.delete(7).subscribe();
    const remove = http.expectOne('/api/topics/7');
    expect(remove.request.method).toBe('DELETE');
    remove.flush(null, { status: 204, statusText: 'No Content' });
  });
});
