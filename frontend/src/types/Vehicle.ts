/**
 * 車両
 * @param id - 管理ID
 * @param number - ナンバー
 * @param model - 車種
 * @param inspected_on - 車検日
 * @param liability_insuranced_on - 自賠責保険
 * @param voluntary_insuranced_on - 任意保険
 */

export interface Vehicle {
  id: number;
  number: string;
  model: string;
  inspected_on: Date | undefined;
  liability_insuranced_on: Date | undefined;
  voluntary_insuranced_on: Date | undefined;
}